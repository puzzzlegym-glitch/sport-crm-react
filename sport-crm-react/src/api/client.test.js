import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { api } from './client';

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
