User Profile (module for Omeka S)
==================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[User Profile] is a module for [Omeka S] that allows to create new settings for
users, for example the phone, or the organisation. The settings are manageable
by the user in the standard admin form, in the site guest user form, or via the
rest api.


Installation
------------

Install optional modules [Guest] and [Generic].

Uncompress files and rename module folder "UserProfile".

See general end user documentation for [installing a module].


Usage
-----

### Configuration

The fields are added via the config form of the module. The main option creates
a [Zend/Laminas form], so the the options are the one used to create html input
fields with a name, a type, attributes and options.

There are some specific option keys for each element:
- `exclude_admin_show` (default: `false`)
  Don't display the value in the admin user board.
- `exclude_admin_edit` (default: `false`)
  Forbid user to edit the value and don't append the element to the admin user
  form.
  When an element is not available in public or admin form, it's still available
  via api and settings and can be used to store any data.
- `exclude_public_show` (default: `false`)
  Don't display the value in the public user board (module Guest).
- `exclude_public_edit` (default: `false`)
  Forbid guest user to edit the value and don't append the element to the public
  user form (module Guest).
- `element_group` (default: `profile`)
  It is used by Omeka S v4 and allows to create fieldsets. You can create any
  dynamic groups you want. The default is `profile` so you don't need to set it.

If one of the element is a select of a list, it is recommended to use module
[Table], that simplifies configuration and that allows to use the same table
in different places.

Three formats are allowed: `ini`, `xml`, or `json`. Each format has pros and
cons. The format `ini` is more readable, `xml` is designed for experts and `json`
is more common.

The main point to check are the keys, but as long as you use standard keys, it's
not an issue. With the format `ini`, two characters are forbidden in keys : the
dot "`.`"  and the vertical apostrophe “`'`”. You can replace the last by a real
apostrophe “`’`” instead, but you cannot replace the dot. In that case, use
formats `xml` or `json`. With xml, the keys cannot contains space, greater sign
`>`, lower sign `<`, single `'` and double quote `"`". With json, some
characters should be escaped, and the data must be well formed, in particular
without trailing comma `,` on last key of each group.

- `ini`

See an example in [Omeka themes].

```ini
elements.userprofile_phone.name                                 = "userprofile_phone"
elements.userprofile_phone.type                                 = "Tel"
elements.userprofile_phone.options.element_group                = "profile"
elements.userprofile_phone.options.label                        = "Phone"
elements.userprofile_phone.attributes.id                        = "userprofile_phone"

elements.userprofile_organisation.name                          = "userprofile_organisation"
elements.userprofile_organisation.type                          = "Select"
elements.userprofile_organisation.options.element_group         = "profile"
elements.userprofile_organisation.options.label                 = "Organisation"
elements.userprofile_organisation.options.empty_option          = ""
elements.userprofile_organisation.options.value_options[none]   = "None"
elements.userprofile_organisation.options.value_options[Alpha]  = "Alpha"
elements.userprofile_organisation.options.value_options[Beta]   = "Beta"
elements.userprofile_organisation.options.value_options[Gamma’s Delta] = "Gamma’s Delta"
elements.userprofile_organisation.attributes.id                 = "userprofile_organisation"
elements.userprofile_organisation.attributes.class              = "chosen-select"
elements.userprofile_organisation.attributes.data-placeholder   = "Select an organisation…"
```

- `xml`

```xml
<elements>
    <userprofile_phone>
        <name>userprofile_phone</name>
        <type>Tel</type>
        <options>
            <element_group>profile</element_group>
            <label>Phone</label>
        </options>
        <attributes>
            <id>userprofile_phone</id>
        </attributes>
    </userprofile_phone>

    <userprofile_organisation>
        <name>userprofile_organisation</name>
        <type>Select</type>
        <options>
            <element_group>profile</element_group>
            <label>Organisation</label>
            <empty_option></empty_option>
            <value_options>
                <value>none</value>
                <label>None</label>
            </value_options>
            <value_options>
                <value>alpha</value>
                <label>Alpha</label>
            </value_options>
            <value_options>
                <value>beta</value>
                <label>Beta</label>
            </value_options>
            <value_options>
                <value>Gamma’s Delta</value>
                <label>Gamma’s Delta</label>
            </value_options>
        </options>
        <attributes>
            <id>userprofile_organisation</id>
            <class>chosen-select</class>
            <data-placeholder>Select an organisation…</data-placeholder>
        </attributes>
    </userprofile_organisation>
</elements>
```

- `json`

```json
{
    "elements":{
        "userprofile_phone": {
            "name": "userprofile_phone",
            "type": "Tel",
            "options": {
                "element_group": "profile",
                "label": "Phone"
            },
            "attributes": {
                "id": "userprofile_phone"
            }
        },

        "userprofile_organisation": {
            "name": "userprofile_organisation",
            "type": "Select",
            "options": {
                "element_group": "profile",
                "label": "Organisation",
                "empty_option": "",
                "value_options": {
                    "none": "None",
                    "Alpha": "Alpha",
                    "Beta": "Beta",
                    "Gamma’s Delta": "Gamma’s Delta"
                }
            },
            "attributes": {
                "id": "userprofile_organisation",
                "class": "chosen-select",
                "data-placeholder": "Select an organisation…"
            }
        }
    }
}
```


### Rest api

For rest api, use something like:

```sh
curl --data '{"o:email":"test.0001@test.com","o:name":"Test 0001","o:role":"researcher","o:is_active":true,"o:setting":{"locale":"fr","default_resource_template":"","userprofile_organisation":"Alpha"}}' --header "Content-Type: application/json" 'https://example.org/api/users?key_identity=xxx&key_credential=yyy'
```

Note that you need an [api credential key] to create, read, update, and delete
user data.


TODO
----

- [ ] Use the form validator in order to check any element.
- [x] In v4, allows to create dynamic group.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
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

* Copyright Daniel Berthereau, 2019-2024 (see [Daniel-KM] on GitLab)

This module is built on a development made for [Fondation Maison de Salins] and
will be used for the future [digital library Manioc] of [Université des Antilles]
et [Université de la Guyane], currently managed with [Greenstone].


[User Profile]: https://gitlab.com/Daniel-KM/Omeka-S-module-UserProfile
[Omeka S]: https://omeka.org/s
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Zend/Laminas form]: https://docs.laminas.dev/laminas-config/reader
[Table]: https://gitlab.com/Daniel-KM/Omeka-S-module-Table
[Omeka themes]: https://omeka.org/s/docs/developer/themes/theme_settings
[api credential key]: https://omeka.org/s/docs/developer/api/rest_api/#authentication
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-UserProfile/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Fondation Maison de Salins]: https://collections.maison-salins.fr
[digital library Manioc]: http://www.manioc.org
[Université des Antilles]: http://www.univ-antilles.fr
[Université de la Guyane]: https://www.univ-guyane.fr
[Greenstone]: http://www.greenstone.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
