Custom Mustache Engine Wrapper
==============================

*A work in progress*

This engine is being tested with simple YAML Database Engine, which can return
results similar to [SquareSpace's full page JSON example][JSONExample].

The full page representation (collectio, item, items, website,...) involves
queries for various contents. This class uses expanders to fullfil the task.

To fully use the potential of full page JSON representation, some data
preprocessing is necessary, thus the class name.

[JSONExample]: http://base-template.squarespace.com/blog/?format=json-pretty

The `render()` method has conditionals to escape from rendering HTML and display
JSON data or formated. Just add `?format=json` or `?format=json-pretty` to
the URL.

Data Expanders
--------------

This component introdudes powerfull concept of data expander.

Data expanders can be defined in the incoming data (which can be considered as
data templates) using the `expanderName()` as the attribute name. Value of the
attribute is then passed to the expander. Expanders are callables that when
passed arguments replace the contents of the arguments definition merging result
with any sibling arguments.

The primary cause was to be able to dynamically replace definitions with results
of queries. Example:

```yaml
title: Page
navigation:
  -
    link(): {_id: 123}    # yes you can still have other data
    class: active         # merged with the expander results
  - link(): {_id: 234}
  - link(): {_id: 345}
```

`link()` is an expander that could generate the href and title of the navigation
link with id being passed as an argument.

See [Flat YAML DB's methods][YAMLDBExpanders] for definition of such expanders.
Constructing this Mustache wrapper with these helpers could be done like this:

```php
DependencyContainer::set('global::dataExpanders', array(
    'link' => function($args) {
        return DependencyContainer::get('global::db')->expanderLink($args);
    },
    'href' => function($args) {
        return DependencyContainer::get('global::db')->expanderHref($args);
    },
    'title' => function($args) {
        return DependencyContainer::get('global::db')->expanderTitle($args);
    },
    'query' => function($args) {
        return DependencyContainer::get('global::db')->expanderQuery($args);
    }
));
```

[YAMLDBExpanders]: https://github.com/attitude/flat-yaml-db/blob/develop/Element.php


Filters
-------

Filters (uses same array of callables as Mustache helpers) is a powerful tool:

```yaml
---
firstName: John
lastName: Travolta
...
```
```html
<h1>{{ firtName}} {{ lastName | uppercase }}</h1>
```

Produces:

```html
<h1>John TRAVOLTA</h1>
```

Mustache filters can be even chained:

```html
<a title="{{title | sentencecase | plaintext }}">{{ title | sentencecase }}</a>
```

See [Shopify's documentation to see some expamples][SHOPIFYLiquid]. Even
Angular.JS has [support][ANGULARJSSupport] for [basic filters][ANGULARFilters]
out of the box.

The wrapper gets Mustache set up with `{%FILTERS%}` pragma enabled by default
without need to explicitly set it up in each of the templates.

[SHOPIFYLiquid]: docs.shopify.com/themes/liquid-basics/output/
[ANGULARJSSupport]: http://docs.angularjs.org/guide/filter
[ANGULARFilters]: http://docs.angularjs.org/api/ng/filter

Data Translations
-----------------

Here's an example of multilanguage data:

```yaml
---
_id: services
_type: collection
_collection: homepage
route:
    sk_SK: /sluzby
    en_EN: /services
title:
    sk_SK: Služby
    en_EN: Services
subtitle:
    sk_SK: Internetové technológie
    en_EN: Internet technologies
navigationTitle:
    sk_SK: Služby
    en_EN: Services
tags: [mainSiteSection]
content: ~
template: services
...
```

Passing the `sk_SK` locale as 2nd argument to the `render()` method filters the
data as if you would would provide:

```yaml
---
_id: services
_type: collection
_collection: homepage
route: /sluzby
title: Služby
subtitle: Internetové technológie
navigationTitle: Služby
tags: [mainSiteSection]
content: ~
template: services
...
```

This way templates stay intact of language switching logic.

Mising attributes and context lookup issue
------------------------------------------

This problem is one of the first you'll run into when using Mustache. Example:

```yaml
---
items:
    -
        href: #1
        text: root item 1
    -
        href: #2
        text: root item 2
content:
    title: Some title
    navigation:
        title: Site navigation
        items:
            -
                href: /
                text: Home
                # no items here (no submenu) << THE PROBLEM
            -
                href: /services
                text: Services
                items: # has submenu
                    -
                        href: /services/service1
                        text: Service 1
                    -
                        href: /services/service2
                        text: Service 2
...
```
```html
<nav>
    <h1>{{ content.title }}</h1>
    <ul>
        {{#content.navigation.items}}
        <li>
            <a href="{{href}}" title="{{text}}">{{ text }}</a>
            <ul>
                {{#items}}
                <li>
                    <a href="{{href}}" title="{{text}}">{{ text }}</a>
                </li>
                {{/items}}
            </ul>
        </li>
        {{/content.navigation.items}}
    </ul>
</nav>
```

Would produce:

```html
<nav>
    <h1>Some title</h1>
    <ul>
        <li>
            <a href="&#x2F;" title="Home">Home</a>
            <!--
                As the Home has no submenu items defined, Mutache looks up the
                context up to the root until items is found.
            -->
            <ul>
                <li>
                    <a href="#1" title="root item 1">root item 1</a>
                </li>
                <li>
                    <a href="#2" title="root item 2">root item 2</a>
                </li>
            </ul>
        </li>
        <li>
            <a href="&#x2F;services" title="Service">Service</a>
            <ul>
                <li>
                    <a href="&#x2F;services&#x2F;service1" title="Service 1">Service 1</a>
                </li>
                <li>
                    <a href="&#x2F;services&#x2F;service2" title="Service 2">Service 2</a>
                </li>
            </ul>
        </li>
    </ul>
</nav>
```

The `fixMissingKeys()` fixes the issue.

Items exist issue
-----------------

Passing data where items are empty example:

```yaml
title: Fancy book title
chapters: []
authors:
 - John Travolta
 - Steven Segal
```

```html
<h1>{{title}}<h1>
<ul class="chapters">
    {{#chapters}}
    <li>{{ . }}</li>
    {{/chapters}}
</ul>
<ul class="authors">
    {{#authors}}
    <li>{{ . }}</li>
    {{/authors}}
</ul>
```

would still produce

```html
<h1>Fancy book title</h1>
<ul class="chapters">
</ul>
<ul class="authors">
    <li>John Travolta</li>
    <li>Steven Segal</li>
</ul>
```

The `arraysHaveItems()` fixes the issue by adding `hasItems` key next to every
array. The data passed to renderer would change to:

```yaml
title: Fancy book title
chapters: []
authors:
 - John Travolta
 - Steven Segal
hasAuthors: true
```

And therefore it is possible to change the template to:


```html
<h1>{{title}}<h1>
{{#hasChapters}}
<ul class="chapters">
    {{#chapters}}
    <li>{{ . }}</li>
    {{/chapters}}
</ul>
{{/hasChapters}}
{{#hasAuthors}}
<ul class="authors">
    {{#authors}}
    <li>{{ . }}</li>
    {{/authors}}
</ul>
{{/hasAuthors}}
```

Since only `hasAuthors` evaluates as true (missing `hasChapters` to false), the
result would be:

```html
<h1>Fancy book title</h1>
<ul class="authors">
    <li>John Travolta</li>
    <li>Steven Segal</li>
</ul>
```

Mustache Setup
--------------

By defining dependencies using the DependencyContainer class, this wrapper class
will handles most of the processes for you.

Refer to [original Mustache setup][] for more explanation.

[MUSTACHESetup]: https://github.com/bobthecow/mustache.php/wiki

---

Enjoy and let me know what you think.

[@martin_adamko](https://twitter.com/martin_adamko)
