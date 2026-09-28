import { describe, it, expect } from 'vitest';
import { computePermissions } from './AuthContext';

describe('computePermissions (1:1 з getPermissions() у sidebar.js)', () => {
  it('SuperAdmin поза клубом — лише перегляд платформи', () => {
    const perms = computePermissions({
      user: { is_superadmin: true },
      in_club_mode: false,
    });
    expect(perms).toEqual({
      canWrite: false,
      isOwner: false,
      isSuperAdmin: true,
      level: 100,
      permissions: {},
      has: expect.any(Function),
    });
  });

  it('SuperAdmin у режимі клубу — бачить рівно те саме, що й реальний власник (бекенд підставляє роль owner)', () => {
    const perms = computePermissions({
      user: { is_superadmin: true },
      in_club_mode: true,
      club_role: { level: 80 }, // auth_api.php у режимі клубу підставляє SuperAdmin роль "owner"
      permissions: {},
    });
    expect(perms.isSuperAdmin).toBe(true); // прапорець акаунту лишається — для exit-club/платформного UI
    expect(perms.level).toBe(80);
    expect(perms.canWrite).toBe(true);
    expect(perms.isOwner).toBe(true);
  });

  it('SuperAdmin у режимі клубу без реальної clubRole (порожній club_role) — не форсує доступ', () => {
    const perms = computePermissions({
      user: { is_superadmin: true },
      in_club_mode: true,
      club_role: null,
      permissions: {},
    });
    expect(perms.level).toBe(0);
    expect(perms.canWrite).toBe(false);
    expect(perms.isOwner).toBe(false);
  });

  it('Owner (level >= 80) без серверних permissions — canWrite і isOwner true через fallback', () => {
    const perms = computePermissions({
      user: { is_superadmin: false },
      in_club_mode: true,
      club_role: { level: 80 },
      permissions: {},
    });
    expect(perms).toEqual({
      canWrite: true,
      isOwner: true,
      isSuperAdmin: false,
      level: 80,
      permissions: {},
      has: expect.any(Function),
    });
  });

  it('Manager (level 50-79) без серверних permissions — canWrite true, isOwner false', () => {
    const perms = computePermissions({
      club_role: { level: 50 },
      permissions: {},
    });
    expect(perms.canWrite).toBe(true);
    expect(perms.isOwner).toBe(false);
  });

  it('Trainer (level < 50) без серверних permissions — canWrite і isOwner false', () => {
    const perms = computePermissions({
      club_role: { level: 30 },
      permissions: {},
    });
    expect(perms.canWrite).toBe(false);
    expect(perms.isOwner).toBe(false);
    expect(perms.level).toBe(30);
  });

  it('Серверні permissions мають пріоритет над fallback по рівню', () => {
    // Низький рівень (10), але сервер явно дозволив clients.create і finance.delete
    const perms = computePermissions({
      club_role: { level: 10 },
      permissions: { 'clients.create': true, 'finance.delete': true },
    });
    expect(perms.canWrite).toBe(true);
    expect(perms.isOwner).toBe(true);
  });

  it('Серверні permissions можуть явно забороняти навіть при високому рівні', () => {
    // Високий рівень (90), але сервер явно заборонив clients.create/invoices.create і finance.delete/users.invite
    const perms = computePermissions({
      club_role: { level: 90 },
      permissions: {
        'clients.create': false,
        'invoices.create': false,
        'finance.delete': false,
        'users.invite': false,
      },
    });
    expect(perms.canWrite).toBe(false);
    expect(perms.isOwner).toBe(false);
  });

  it('Відсутній auth (null) — безпечні дефолти', () => {
    expect(computePermissions(null)).toEqual({
      canWrite: false,
      isOwner: false,
      isSuperAdmin: false,
      level: 0,
      permissions: {},
      has: expect.any(Function),
    });
  });

  it('has() перевіряє конкретний slug і має пріоритет над рівнем', () => {
    const perms = computePermissions({
      club_role: { level: 30 },
      permissions: { 'tariffs.manage': true, 'clients.delete': false },
    });
    expect(perms.has('tariffs.manage')).toBe(true);
    expect(perms.has('clients.delete')).toBe(false);
    expect(perms.has('unknown.slug')).toBe(false);
  });

  it('has() для SuperAdmin поза клубом — завжди true (платформний доступ)', () => {
    const perms = computePermissions({ user: { is_superadmin: true }, in_club_mode: false });
    expect(perms.has('anything.at.all')).toBe(true);
  });

  it('has() для SuperAdmin у режимі клубу — рахується як для власника, без universal-bypass', () => {
    const perms = computePermissions({
      user: { is_superadmin: true },
      in_club_mode: true,
      club_role: { level: 80 },
      permissions: { 'clients.delete': false },
    });
    expect(perms.has('clients.delete')).toBe(false);
    expect(perms.has('unknown.slug')).toBe(false);
  });
});
