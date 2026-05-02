# Onboarding Redirect Patch

In `assets/js/onboarding.js`, replace this line inside `finish(topicItem)`:

```js
const href = quizKey ? `quiz.php?key=${encodeURIComponent(quizKey)}` : '/';
```

with:

```js
const href = 'recommendations.php';
```

And replace the start button text:

```js
start.textContent = quizKey ? 'Quiz starten' : 'Zur Übersicht';
```

with:

```js
start.textContent = 'Empfehlungen ansehen';
```

This creates the intended flow:

```text
Onboarding → Recommendations → Quiz
```
