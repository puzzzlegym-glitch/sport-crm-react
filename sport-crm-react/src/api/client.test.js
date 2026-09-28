import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { api } from './client';
import { startDemo, stopDemo, isDemoActive } from '../demo';

function mockFetchOnce({ status = 200, text = '{}' }) {
  global.fetch = vi.fn().mockResolvedValue({
    status,
    text: () => Promise.resolve(text),
  });
}

describe('api()', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    delete window.location;
    window.location = { ...originalLocation, href: '' };
  });

  afterEach(() => {
    window.location = originalLocation;
    vi.restoreAllMocks();
  });

  it('надсилає POST з credentials: include і Content-Type: application/json', async () => {
    mockFetchOnce({ text: '{"success":true}' });
    await api('check', { foo: 'bar' }, 'auth');

    expect(global.fetch).toHaveBeenCalledWith(
      '/api/auth_api.php?action=check',
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ foo: 'bar' }),
      })
    );
  });

  it('невідомий apiType падає назад на auth', async () => {
    mockFetchOnce({ text: '{"success":true}' });
    await api('check', {}, 'not-a-real-module');
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/auth_api.php?action=check',
      expect.anything()
    );
  });

  it('успішна відповідь повертає розпарсений JSON', async () => {
    mockFetchOnce({ text: '{"success":true,"data":{"id":1}}' });
    const res = await api('check', {}, 'auth');
    expect(res).toEqual({ success: true, data: { id: 1 } });
  });

  it('401 редиректить на /login і не намагається парсити тіло', async () => {
    mockFetchOnce({ status: 401, text: 'irrelevant' });
    const res = await api('check', {}, 'auth');
    expect(res).toEqual({ success: false });
    expect(window.location.href).toBe('/login');
  });

  it('не-JSON відповідь повертає керовану помилку замість падіння', async () => {
    mockFetchOnce({ status: 200, text: '<html>500 error</html>' });
    const res = await api('check', {}, 'auth');
    expect(res).toEqual({ success: false, error: 'Невірна відповідь сервера' });
  });

  it('мережева помилка (fetch reject) повертає керовану помилку', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('Failed to fetch'));
    const res = await api('check', {}, 'auth');
    expect(res).toEqual({ success: false, error: 'Немає підключення до сервера' });
  });
});

describe('api() у демо-режимі', () => {
  beforeEach(() => startDemo());
  afterEach(() => {
    stopDemo();
    vi.restoreAllMocks();
  });

  it('login іде на справжній сервер і вимикає демо', async () => {
    mockFetchOnce({ text: '{"success":true}' });
    const res = await api('login', { email: 'a@b.ua', password: 'x' }, 'auth');
    expect(res).toEqual({ success: true });
    expect(global.fetch).toHaveBeenCalledWith('/api/auth_api.php?action=login', expect.anything());
    expect(isDemoActive()).toBe(false);
  });

  it('реєстрація теж іде на справжній сервер', async () => {
    mockFetchOnce({ text: '{"success":true}' });
    await api('check_email', { email: 'a@b.ua' }, 'register');
    expect(global.fetch).toHaveBeenCalledWith('/api/register_api.php?action=check_email', expect.anything());
  });

  it('звичайні дії в демо не звертаються до сервера', async () => {
    global.fetch = vi.fn();
    await api('get_list', {}, 'clients');
    expect(global.fetch).not.toHaveBeenCalled();
    expect(isDemoActive()).toBe(true);
  });
});
