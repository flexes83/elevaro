# Elevaro Onboarding DB Integration

Dieses Paket ergänzt:

- `onboarding.php`
- dynamische Curriculum API
- DB-Helper für Bundesländer, Schularten, Klassen, Fächer und Themen
- passende Onboarding-CSS/JS

## Voraussetzung

Die DB aus `elevaro_db_setup.zip` muss importiert sein.

## Einstieg

Button auf Startseite später aufrufen mit:

```html
<a href="onboarding.php" class="btn btn-primary">Los geht’s</a>
```

## API Beispiele

```text
/api/curriculum.php?action=states
/api/curriculum.php?action=school_types&state=bw
/api/curriculum.php?action=grades&state=bw&school_type=gymnasium
/api/curriculum.php?action=subjects&state=bw&school_type=gymnasium&grade=5
/api/curriculum.php?action=topics&state=bw&school_type=gymnasium&grade=5&subject=mathe
```
