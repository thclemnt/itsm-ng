# SPDX-License-Identifier: GPL-2.0-or-later
"""Extract only a matching PHP ELF debug file; never install or replace PHP."""
import hashlib
import json
import pathlib
import platform
import re
import resource
import shutil
import subprocess
import sys
import tarfile
import tempfile

binary = pathlib.Path(sys.argv[1]).resolve()
root = pathlib.Path(sys.argv[2])
metadata = {"status": "unavailable"}
root.mkdir(mode=0o700)


def output(args):
    return subprocess.check_output(args, stderr=subprocess.DEVNULL, timeout=10, text=True)


def build_id(path):
    match = re.search(r"Build ID: ([a-f0-9]{16,128})", output(["readelf", "-n", str(path)]))
    if match is None:
        raise ValueError("ELF build ID unavailable")
    return match[1]


def cap_download():
    _, hard = resource.getrlimit(resource.RLIMIT_FSIZE)
    limit = 128 * 1024 * 1024
    resource.setrlimit(resource.RLIMIT_FSIZE, (limit if hard == resource.RLIM_INFINITY else min(limit, hard), hard))


try:
    identity = build_id(binary)
    metadata["build_id"] = identity
    # setup-php's php-builder cache overlays binaries without installing dpkg
    # packages. Package ownership can therefore describe a different PHP build.
    runtime = json.loads(root.parent.joinpath("web-api-runtime.json").read_text())
    version = runtime["php"]
    system = platform.freedesktop_os_release()
    machine = platform.machine()
    if (not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version)
            or pathlib.Path(runtime["binary"]).resolve() != binary
            or runtime["thread_safe"] not in (0, 1) or runtime["debug"] != 0
            or system.get("ID") != "ubuntu"
            or not re.fullmatch(r"[0-9]{2}\.[0-9]{2}", system.get("VERSION_ID", ""))
            or machine not in ("x86_64", "aarch64", "arm64")):
        raise ValueError("No supported setup-php cache identity")
    threads = "zts" if runtime["thread_safe"] else "nts"
    architecture = "" if machine == "x86_64" else "_arm64"
    asset = f"php_{version}-{threads}-dbgsym+ubuntu{system['VERSION_ID']}{architecture}.tar.zst"
    url = "https://github.com/shivammathur/php-ubuntu/releases/download/builds/" + asset.replace("+", "%2B")
    metadata.update(source="shivammathur/php-ubuntu", asset=asset, version=version)
    relative = pathlib.Path("usr/lib/debug/.build-id") / identity[:2] / (identity[2:] + ".debug")
    destination = root / relative
    # A disposable directory scopes all archive bytes and excludes unowned files.
    with tempfile.TemporaryDirectory(prefix="download-", dir=root) as directory:
        archive_path = pathlib.Path(directory) / "symbols.tar.zst"
        subprocess.run(["curl", "--fail", "--silent", "--show-error", "--location",
                        "--proto", "=https", "--proto-redir", "=https", "--max-time", "45",
                        "--max-filesize", str(128 * 1024 * 1024), "--output", str(archive_path), url],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=50,
                       preexec_fn=cap_download, check=True)
        if not 0 < archive_path.stat().st_size <= 128 * 1024 * 1024:
            raise ValueError("Debug cache archive exceeds its bound")
        with archive_path.open("rb") as downloaded:
            metadata["archive_sha256"] = hashlib.file_digest(downloaded, "sha256").hexdigest()
        process = subprocess.Popen(["zstd", "--decompress", "--stdout", "--memory=128MB", str(archive_path)],
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        try:
            with tarfile.open(fileobj=process.stdout, mode="r|") as archive:
                for member in archive:
                    if member.offset_data + member.size > 1024 * 1024 * 1024:
                        raise ValueError("Debug cache decompressed scan exceeds its bound")
                    if member.name.removeprefix("./") != str(relative):
                        continue
                    if not member.isfile() or not 0 < member.size <= 128 * 1024 * 1024:
                        raise ValueError("Matching debug member exceeds its bound or is not a regular file")
                    # Never expose an unverified file in GDB's debug search path,
                    # including when readelf or the collector times out.
                    candidate = pathlib.Path(directory) / "matched.debug"
                    with archive.extractfile(member) as source, candidate.open("xb") as target:
                        shutil.copyfileobj(source, target, length=1024 * 1024)
                    if candidate.stat().st_size != member.size or build_id(candidate) != identity:
                        raise ValueError("Debug file build ID or size does not match PHP")
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    candidate.rename(destination)
                    metadata["status"] = "matched"
                    break
        finally:
            process.stdout.close()
            if process.poll() is None:
                process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError, tarfile.TarError) as error:
    # Exception messages can contain mirror URLs or command arguments: do not log them.
    metadata["error_type"] = type(error).__name__
finally:
    root.parent.joinpath("web-api-symbols.json").write_text(json.dumps(metadata, indent=2))
