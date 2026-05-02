const state = {
  step: 0,
  values: {
    name: null,
    state: null,
    school_type: null,
    grade: null,
    subject: null
  }
};

const steps = [
  {
    key: 'name',
    type: 'input',
    title: 'Wie dürfen wir dich nennen?',
    text: 'Damit sich Elevaro ein bisschen persönlicher anfühlt.'
  },
  {
    key: 'state',
    title: 'Wo gehst du zur Schule?',
    action: 'states'
  }
];

const els = {
  progress: document.getElementById('stepProgress'),
  title: document.getElementById('stepTitle'),
  text: document.getElementById('stepText'),
  choices: document.getElementById('choices'),
  back: document.getElementById('backBtn'),
  skip: document.getElementById('skipBtn')
};

function render() {
  const step = steps[state.step];

  els.title.textContent = step.title;
  els.text.textContent = step.text || '';
  els.choices.innerHTML = '';

  if (step.type === 'input') {
    const input = document.createElement('input');
    input.className = 'form-control mb-3';
    input.placeholder = 'Dein Vorname';

    const btn = document.createElement('button');
    btn.className = 'btn btn-primary';
    btn.textContent = 'Weiter';

    btn.onclick = () => {
      const name = input.value.trim();
      if (name) {
        localStorage.setItem('elevaro_profile_name', name);
      }
      next();
    };

    els.choices.appendChild(input);
    els.choices.appendChild(btn);
  }
}

function next() {
  state.step++;
  render();
}

els.skip.onclick = () => {
  next();
};

els.back.onclick = () => {
  if (state.step > 0) {
    state.step--;
    render();
  }
};

render();
