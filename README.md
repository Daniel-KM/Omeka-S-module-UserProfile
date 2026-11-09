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

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

* From the zip

Download the last release [UserProfile.zip] from the list of releases and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `UserProfile`.

* For test

The module includes a test suite. Run it from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/UserProfile/phpunit.xml --testdox
```

* Upgrade from module Guest Profile

The profiles of the old module Guest Profile are converted automatically when
this module is installed or upgraded: the fields "Statut" and "Autre statut…"
are added to the config as `userprofile_status` and `userprofile_status_other`,
in the simple syntax when the config is empty or in this syntax, or in ini when
the config is in ini, and the values of the users are copied. The old values are kept in the user settings. Then the module Guest
Profile can be uninstalled.


Usage
-----

### Configuration

The fields are added via the config form of the module, so only the admins can
manage them. The main option creates a [Zend/Laminas form], so the the options
are the one used to create html input fields with a name, a type, attributes and
options.

There are some specific option keys for each element:
- `show` (default: `all`)
  Who can see the value: `all`, `admin` (the admin user board only), `user`
  (the public user board only, with module Guest) or `none`.
- `edit` (default: `all`)
  Who can edit the value: `all`, `admin`, `user` or `none`. The element is not
  appended to the forms of the others. For example, `admin` can be used for a
  category that a user must not change, and `user` for personal settings that
  an admin must not change.
- `element_group` (default: `profile`)
  It is used by Omeka S v4 and allows to create fieldsets. You can create any
  dynamic groups you want. The default is `profile` so you don't need to set it.

The old boolean options `exclude_admin_show`, `exclude_admin_edit`,
`exclude_public_show` and `exclude_public_edit` are still read, but they are
converted automatically during the upgrade.

The options are rights. Via the rest api, they depend on the role of the user,
an admin or not: the values that cannot be edited are skipped silently and the
values that cannot be displayed are not output. In the admin interface, the
rights of edition depend on the role too and the rights of display on the
interface. The public interface is always for users. The other rules of Omeka
apply: a user can update only the users allowed by the acl, generally only
themself. The internal processes are not limited, so an element hidden to all
can store any data, but the values remain readable in the database and by the
other modules.

Some examples:

| `show`  | `edit`  | Example                                                                   |
|---------|---------|---------------------------------------------------------------------------|
| `all`   | `all`   | Phone, organisation or subject of research, filled by the user            |
| `all`   | `admin` | Category of reader or validated registration, set by the admins only      |
| `admin` | `admin` | Internal note about the user, hidden to them                              |
| `admin` | `user`  | Answer to a question of the registration form, then reserved to admins    |
| `user`  | `user`  | Personal preferences of the public interface, hidden to the admins        |
| `none`  | `none`  | Technical data set by a process: external id (sso, ldap), last sync date  |

```ini
elements.userprofile_note.name                                  = "userprofile_note"
elements.userprofile_note.type                                  = "Textarea"
elements.userprofile_note.options.label                         = "Internal note"
elements.userprofile_note.options.show                          = "admin"
elements.userprofile_note.options.edit                          = "admin"

elements.userprofile_category.name                              = "userprofile_category"
elements.userprofile_category.type                              = "Select"
elements.userprofile_category.options.label                     = "Category"
elements.userprofile_category.options.value_options[student]    = "Student"
elements.userprofile_category.options.value_options[researcher] = "Researcher"
elements.userprofile_category.options.edit                      = "admin"
```

If one of the element is a select of a list, it is recommended to use module
[Table], that simplifies configuration and that allows to use the same table
in different places.

The simple syntax is the default one: it is a yaml mapping where each key is
the name of a field and the value its label, or an object with the keys `label`,
`type`, `required`, `values` (the choices of a select, radio or multicheckbox),
`options` and `attributes`. The config form edits it as a form, with a button
to switch to the text. The rights are set in the options, so the form keeps
them:

```yaml
userprofile_phone:
  label: Phone
  type: tel
userprofile_category:
  label: Category
  type: select
  values:
    student: Student
    researcher: Researcher
  options:
    edit: admin
userprofile_note: Note
```

The types are `text`, `textarea`, `email`, `tel`, `number`, `url`, `date`,
`time`, `datetime`, `password`, `hidden`, `select`, `radio`, `checkbox` and
`multicheckbox`, and the selects of Omeka `itemset`, `site`, `resourcetemplate`,
`resourceclass` and `property`. With the matching module, the types
`customvocab`, `thesaurus` and `table` are selects that require the option
`custom_vocab_id`, `thesaurus` (the id of the scheme) or `table` (the id or the
slug). Add the option `empty_option: ''` to keep the field optional and the
attribute `multiple: true` to select many values. The names of the fields may
contain only letters without accent, digits, `_` and `-`. For other types, use
the full Laminas specifications below.

The full Laminas specifications use the root key `elements`, so they cannot be
edited as a form. Four formats are allowed: `yaml`, `ini`, `xml`, or `json`.
Each format has pros and cons. The formats `yaml` and `ini` are more readable, `xml` is designed for
experts and `json` is more common. The parser of the format `yaml` is included
in the module [Common].

The config form can convert the list of fields to another format when it is
saved, the simple syntax included. The comments are not kept. A format is disabled when something in the
list cannot be stored in it, for example a dot in a key with the format `ini`.
When the list is modified at the same time and cannot be converted, nothing is
saved and the error indicates the keys to fix.

The main point to check are the keys, but as long as you use standard keys, it's
not an issue. With the format `ini`, two characters are forbidden in keys : the
dot "`.`"  and the vertical apostrophe “`'`”. You can replace the last by a real
apostrophe “`’`” instead, but you cannot replace the dot. In that case, use
formats `xml` or `json`. With xml, the keys cannot contains space, greater sign
`>`, lower sign `<`, single `'` and double quote `"`". With json, some
characters should be escaped, and the data must be well-formed, in particular
without trailing comma `,` on last key of each group.

- `yaml`

The indentation defines the structure, with spaces only (no tabulation). Quote
a value when it contains a colon followed by a space or starts with a special
character.

```yaml
elements:
  userprofile_phone:
    name: userprofile_phone
    type: Tel
    options:
      element_group: profile
      label: Phone
    attributes:
      id: userprofile_phone
  userprofile_organisation:
    name: userprofile_organisation
    type: Select
    options:
      element_group: profile
      label: Organisation
      empty_option: ''
      value_options:
        none: None
        Alpha: Alpha
        Beta: Beta
        Gamma’s Delta: Gamma’s Delta
    attributes:
      id: userprofile_organisation
      class: chosen-select
      data-placeholder: Select an organisation…
```

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

* Copyright Daniel Berthereau, 2019-2026 (see [Daniel-KM] on GitLab)

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
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-UserProfile/-/work_items
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
