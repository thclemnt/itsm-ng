# Profile template fixture reset

The current76cd MariaDB 11.8 functional CI run fails
`ITILTemplate::testGetITILTemplateToUse` at its final Profile cleanup update with
the real `fk_profiles_tickettemplates_id` foreign key. Its positive category,
Entity and Profile templates were created successfully. The reset supplies `-2`
to the Profile's ordinary template selection.

Profile declares its Ticket, Change and Problem template associations as nullable
`EmptySelection` references. The public Profile template dropdown offers an
ordinary empty selection, and `getITILTemplateToUse` skips that empty Profile
selection before checking Entity configuration. Entity has a distinct property
policy: its `-2` sentinel means inheritance and is normalized into the owning
reference mode. These are different roles.

The fixture now resets the Profile through its valid public empty value `0`,
then reloads the actual Profile and checks that its canonical selection is NULL.
The Entity `-2` reset, all created parents, all category/Profile/Entity precedence
assertions, public authorization and outer rollback remain intact. Production
policies and historical schemas are unchanged.

Validation is SOURCE ONLY. PHP syntax/style and the actual three data-provider
cases must be run by ROOT, followed by the relevant functional and provider
suites. No candidate native or CI success is claimed.
