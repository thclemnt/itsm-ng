# SPDX-License-Identifier: GPL-2.0-or-later
"""GDB core reader: source filenames/lines/opcodes only, never PHP values."""
import os
import pathlib
import gdb

repository = str(pathlib.Path(os.environ["GITHUB_WORKSPACE"]).resolve()) + "/"
seen = set()
try:
    # Require DWARF types. Never guess executor-global or VM structure offsets.
    frame = gdb.parse_and_eval("executor_globals.current_execute_data")
    for depth in range(64):
        address = int(frame)
        if not address or address in seen:
            break
        seen.add(address)
        current = frame.dereference()
        function = current["func"]
        if int(function) and int(function["common"]["type"]) in (2, 4):
            filename = function["op_array"]["filename"]
            opline = current["opline"]
            if int(filename) and int(opline):
                length = int(filename["len"])
                if not 0 < length <= 4096:
                    raise ValueError("Invalid source filename length")
                raw = gdb.selected_inferior().read_memory(filename["val"].address, length).tobytes()
                name = raw.decode("utf-8", errors="replace")
                # Exclude outside paths and control characters; never print arbitrary memory.
                name = name[len(repository):] if name.startswith(repository) else "[outside repository]"
                name = "".join(c if c.isprintable() else "?" for c in name)[:512]
                gdb.write("PHP frame %d: %s:%d opcode=%d\n" %
                          (depth, name, int(opline["lineno"]), int(opline["opcode"])))
        frame = current["prev_execute_data"]
    if len(seen) == 64:
        gdb.write("PHP frame limit reached (64).\n")
except (gdb.error, ValueError, OverflowError):
    gdb.write("PHP source frames unavailable: missing matched types or unreadable VM metadata.\n")
