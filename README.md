User Profile (module for Omeka S)
==================================

[User Profile] is a module for [Omeka S] that allows to create new settings for
users, for example the phone, or the organisation. The settings are manageable
by the user in the standard admin form, in the site guest user form, or via the
rest api.


Installation
------------

Install optional modules [Guest] and [Generic].

Uncompress files and rename module folder "UserProfile".

See general end user documentation for [Installing a module].


Usage
-----

### Configuration

The fields are added via the config form of the module. The main option creates
a [Zend/Laminas form], so the the options are the one used to create html input
fields with a name, a type, attributes and options. Three formats are allowed:
`ini`, `xml`, or `json`.

- `ini`

See an example in [Omeka themes].

```ini
elements.userprofile_phone.name          = "userprofile_phone"
elements.userprofile_phone.type          = "Tel"
elements.userprofile_phone.options.label = "Phone"
elements.userprofile_phone.attributes.id = "userprofile_phone"

elements.userprofile_organisation.name                          = "userprofile_organisation"
elements.userprofile_organisation.type                          = "Select"
elements.userprofile_organisation.options.label                 = "Organisation"
elements.userprofile_organisation.options.value_options.none    = "None"
elements.userprofile_organisation.options.value_options.Alpha   = "Alpha"
elements.userprofile_organisation.options.value_options.Beta    = "Beta"
elements.userprofile_organisation.options.value_options.Gamma’s Delta = "Gamma’s Delta"
elements.userprofile_organisation.attributes.id                 = "userprofile_organisation"
elements.userprofile_organisation.attributes.class              = "chosen-select"
elements.userprofile_organisation.attributes.data-placeholder   = "Select an organisation…"
```
- That’s all!

Note: a key cannot contain a vertical apostrophe “`'`”, so use real apostrophe
“`’`” instead.

For rest api, use something like:
```sh
curl --data '{"o:email":"test.0001@test.com","o:name":"Test 0001","o:role":"researcher","o:is_active":true,"o:setting":{"locale":"fr","default_resource_template":"","userprofile_organisation":"Alpha"}}' --header "Content-Type: application/json" 'https://example.org/api/users?key_identity=xxx&key_credential=yyy'
```

Note that you need an [api credential key] to create, read, update, and delete a
user.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2019-2020 (see [Daniel-KM] on GitHub)

This module is built on a development made for [Fondation Maison de Salins] and
will be used for the future [digital library Manioc] of [Université des Antilles et de la Guyane],
currently managed with [Greenstone].


[User Profile]: https://github.com/Daniel-KM/Omeka-S-module-UserProfile
[Omeka S]: https://omeka.org/s
[Guest User]: https://github.com/Daniel-KM/Omeka-S-module-GuestUser
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[Omeka themes]: https://omeka.org/s/docs/developer/themes/theme_settings
[api credential key]: https://omeka.org/s/docs/developer/api/rest_api/#authentication
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-UserProfile/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Fondation Maison de Salins]: https://collections.maison-salins.fr
[digital library Manioc]: http://www.manioc.org
[Université des Antilles et de la Guyane]: http://www.univ-ag.fr
[Greenstone]: http://www.greenstone.org
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
