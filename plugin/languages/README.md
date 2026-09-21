# Translations

This directory holds the plugin's `.pot` template and any `.po`/`.mo`/`.json`
translations. It is otherwise empty on purpose.

## Regenerating the template

```bash
wp i18n make-pot . languages/livepress.pot
wp i18n make-json languages --no-purge     # the editor's strings, for wp.i18n
```

No `.pot` is committed. Generating one by hand looked tempting and was the
wrong call: the real extractor understands plural forms, string context,
translator comments and source references, and a template missing any of
those is worse than none — a translator trusts it, works from it, and never
sees the strings it quietly left out. `wp i18n make-pot` is one command and
does it properly.

`make-json` matters as much as `make-pot` here. The editor is a JavaScript
app and most of the plugin's words are in it; `wp.i18n` reads JSON, not `.mo`,
so a translation that skips that step leaves the entire editor in English
while the admin screens change language around it.

## Text domain

`livepress`, declared in the plugin header and loaded on `init`. Every call
passes it explicitly rather than through a wrapper, because the extractor
reads source text and cannot follow one.
