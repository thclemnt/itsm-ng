# E2E installation readiness

Remote E2E run 37235444441 at the 76cd tree installed successfully on
MariaDB 10.11.19 and printed canonical history completion. The installation
wrapper then failed because it required the obsolete stdout phrase
`No migration needed.`. The log contains no independently captured update
CLI exit status and no browser execution summary.

The wrapper now requires the real update pipeline to succeed, retaining both
CLI and tee failure status with pipefail. Only then does a read-only verifier
check every current canonical ledger version, completed installation state,
core SchemaCheck differences and all four current release publication aliases
through the explicit configured writer. Printed success text is not proof.
The verifier avoids ordinary application bootstrap, which can exit before
its assertions, and neither applies migrations nor repairs schema/data.

The shell contract stubs both application executables and checks successful
canonical output, a nonzero update that prints that same output, a refused
readiness check and failed installation. It proves process ordering/status,
not real database convergence. The native verifier must still run against
the actual installation before any browser result is claimed.

This batch has source inspection and Git whitespace evidence only. Shell
contract execution, PHP syntax, actual installed verification and remote
browser reruns remain pending; the earlier remote run remains failed.
