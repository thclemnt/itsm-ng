# LDAP authentication ownership fixtures

Remote LDAP run 37235444450 executed all 51 methods on MariaDB 13.0.2 at
the 76cd tree, but failed `testLdapAuth` while adding its duplicate-DN fixture.
The test copied a persisted User row, changed only legacy `auths_id`, and
guessed the next directory identifier. Its copied canonical `authldaps_id`
still selected the original directory. `User::normalizeInput` correctly
refused those contradictory owners before persistence.

The fixture now creates an actual inactive alternate directory through public
`AuthLDAP::add`. It first checks that the contradictory copied input still
throws and creates no account for that directory. Then it supplies the same
real directory through both representations and verifies the persisted owner
and duplicated DN. The original real LDAP login and original-directory/DN
assertions remain in place. The inactive duplicate directory is deliberately
excluded from fallback connection attempts. The owning DbTestCase transaction
cleans up the directory and account after the method.

No production authentication policy, foreign key, migration, authorization,
notification or history behavior changes. The public canonical-only User
creation default issue is a separate ownership boundary to investigate; this
fixture correction does not fix or validate it.

Validation so far is source inspection and Git whitespace checks only. PHP
syntax, LDAP execution, both-provider application suites and remote CI reruns
are pending. The previous remote job remains failed.
