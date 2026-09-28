import { buildSeed, SEED_VERSION } from './seedData';

const FLAG_KEY = 'sportcrm_demo_active';
const DATA_KEY = 'sportcrm_demo_state';

let store = null;

function load() {
  try {
    const raw = sessionStorage.getItem(DATA_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      // Стара вкладка, відкрита до деплою з новою формою стану, — скидаємо на свіжий сід
      // замість падіння обробників на відсутніх полях (undefined.filter тощо).
      if (parsed?._v === SEED_VERSION) return parsed;
    }
  } catch { /* ignore corrupted state */ }
  return buildSeed();
}

function ensureStore() {
  if (!store) store = load();
  return store;
}

export function isDemoActive() {
  return sessionStorage.getItem(FLAG_KEY) === '1';
}

export function startDemo() {
  sessionStorage.setItem(FLAG_KEY, '1');
  store = buildSeed();
  persist();
}

export function stopDemo() {
  sessionStorage.removeItem(FLAG_KEY);
  sessionStorage.removeItem(DATA_KEY);
  store = null;
}

export function resetDemo() {
  store = buildSeed();
  persist();
}

export function getStore() {
  return ensureStore();
}

export function persist() {
  try {
    sessionStorage.setItem(DATA_KEY, JSON.stringify(store));
  } catch { /* quota or serialization issue — demo just won't survive a refresh */ }
}

export function nextId(entity) {
  const s = ensureStore();
  const id = s.nextId[entity]++;
  persist();
  return id;
}
