# SPDX-License-Identifier: GPL-2.0-or-later
"""Extract only a matching PHP ELF debug file; never install or replace PHP."""
import json
import pathlib
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
    limit = 64 * 1024 * 1024
    resource.setrlimit(resource.RLIMIT_FSIZE, (limit if hard == resource.RLIM_INFINITY else min(limit, hard), hard))


try:
    identity = build_id(binary)
    metadata["build_id"] = identity
    owner = output(["dpkg-query", "--search", str(binary)]).strip()
    match = re.fullmatch(r"(php[0-9]+\.[0-9]+-cli)(?::[a-z0-9]+)?: " + re.escape(str(binary)), owner)
    if match is None:
        raise ValueError("PHP executable has no unique CLI package owner")
    package = match[1]
    version = output(["dpkg-query", "--show", "--showformat=${Version}", package]).strip()
    metadata.update(package=package, version=version)
    relative = pathlib.Path("usr/lib/debug/.build-id") / identity[:2] / (identity[2:] + ".debug")
    destination = root / relative
    # A disposable directory scopes all archive bytes and excludes unowned files.
    with tempfile.TemporaryDirectory(prefix="download-", dir=root) as directory:
        subprocess.run(["apt-get", "download", package + "-dbgsym=" + version], cwd=directory,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=45,
                       preexec_fn=cap_download, check=True)
        archives = list(pathlib.Path(directory).glob("*.deb"))
        if len(archives) != 1 or archives[0].stat().st_size > 64 * 1024 * 1024:
            raise ValueError("Debug package archive exceeds its bound")
        process = subprocess.Popen(["dpkg-deb", "--fsys-tarfile", str(archives[0])],
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        try:
            with tarfile.open(fileobj=process.stdout, mode="r|") as archive:
                for member in archive:
                    if member.name.removeprefix("./") != str(relative):
                        continue
                    if not member.isfile() or not 0 < member.size <= 128 * 1024 * 1024:
                        raise ValueError("Matching debug member exceeds its bound or is not a regular file")
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    with archive.extractfile(member) as source, destination.open("xb") as target:
                        shutil.copyfileobj(source, target, length=1024 * 1024)
                    if destination.stat().st_size != member.size or build_id(destination) != identity:
                        destination.unlink()
                        raise ValueError("Debug file build ID or size does not match PHP")
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
except (OSError, ValueError, subprocess.SubprocessError, tarfile.TarError) as error:
    # Exception messages can contain mirror URLs or command arguments: do not log them.
    metadata["error_type"] = type(error).__name__
finally:
    root.parent.joinpath("web-api-symbols.json").write_text(json.dumps(metadata, indent=2))
