# Cookie-token rotation: source preparation

This isolated Session follow-up is source-only. No provider, browser, HTTP or
original application test execution has validated the new code or contract.
The parent worker observed a PostgreSQL browser PHP log where
`Auth::setRememberMeCookie()` reported headers already sent after
`User::getAuthToken()` constructed `DateTime` with a NULL `cookie_token_date`.
Source inspection explains the warning: password login with remember-me always
requests forced token rotation, but the old method parsed expiry before checking
whether rotation was forced or any token existed. This is an inherited defect,
not evidence that the Session repository rewrite introduced it.

Expiry is now evaluated only when reusing an existing, unforced cookie token.
An existing token with a missing timestamp is outdated; NULL is not interpreted
as the current date. Forced and absent-token creation skip irrelevant expiry
parsing. Valid stored cookie hashes keep their existing return shape; expired or
undated credentials rotate. New cookie tokens still use `Auth::getPasswordHash`,
while personal tokens keep their established stored plaintext format. Timestamp
storage, native TIMESTAMP mappings and the public User update lifecycle remain
unchanged.

The method also propagates a refused public update. An accepted update alone is
insufficient: User preparation can remove protected token fields, and public
hooks can change persistence while leaving model fields at their attempted
values. The existing User repository reads the stored token with a bound scalar
query on `Orm::create($DB)`'s supplied connection, without reloading User hooks or
trusting managed/model state. PHP byte-exact comparison avoids case-insensitive
database collation admitting a different bcrypt hash. Only a matching persisted
credential is returned. The postcondition does
not undo unrelated fields, history or plugin writes from an accepted public
update; a stripped token can still leave the existing policy's permitted date
update. This is an outcome check, not another authorization policy or a new
transaction guarantee. Reusing an already-loaded valid token retains the
existing behavior and is not a new concurrency guarantee.

Prepared `cookie-tokens.php` uses actual persisted accounts and public methods,
strict `E_ALL` warning handling, and a caller-owned transaction. It covers NULL
token/date creation, forced rotation, existing undated and expired hashes, valid
reuse without a write/history/hook, a loaded-but-unflushed ORM identity bypassed
by the scalar credential read, cookie hashing, personal-token storage,
real pre-update hook refusal, post-update hooks that replace or case-mutate the
stored value without correcting model fields, and an authenticated lower-right actor whose
protected token field is removed by the existing User policy. Failure messages
assert booleans without printing credentials or hashes. A final actual
`Auth::login(..., remember_me: true)` exercises the forced-rotation producer.
It checks the actual remember-me JSON against the stored hash and a second
accepted login with a token-only hook veto, which retains its earlier cookie,
token and date. The fixture restores the COOKIE superglobal as well as its
owned session/language context. That public-method control is not a real HTTP
cookie/header assertion, and the
contract's lower-right login uses this branch's accepted personal-token boundary.

Next run the focused contract on both providers, prove the original method's
NULL-date warning causally with the same external contract, run original User,
Auth and Session tests, and exercise real browser remembered login/cookie
delivery and alternate cookie authentication. Fresh/full portability, complete
application and remote CI gates remain required after source composition. The
overall modernization goal remains open.

Source checks: PHP lint passed for the model, repository and new contract;
repository formatter reported zero changes across all three; whitespace checks
passed. These checks neither compile Doctrine queries nor execute provider or
authentication behavior. Independent source review caught and resolved the
case-insensitive equality postcondition and the compiler's required root alias.
