import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Table from './Table';

const columns = [
  { key: 'name', label: "Ім'я" },
  { key: 'phone', label: 'Телефон' },
  { key: 'actions', label: '', render: () => <button>Редагувати</button> },
];

describe('Table (мобільні картки через className — 1:1 з admin.css)', () => {
  it('показує лоадер, коли loading=true', () => {
    render(<Table columns={columns} rows={[]} loading />);
    expect(screen.getByText('Завантаження...')).toBeInTheDocument();
  });

  it('показує emptyMessage, коли рядків немає', () => {
    render(<Table columns={columns} rows={[]} emptyMessage="Клієнтів ще немає" />);
    expect(screen.getByText('Клієнтів ще немає')).toBeInTheDocument();
  });

  it('перша колонка без render() отримує ellipsis-клас, колонка без mobile-прапорця прихована на мобільному, actions — table-actions-cell', () => {
    render(<Table columns={columns} rows={[{ id: 1, name: 'Іван', phone: '+380' }]} />);
    const nameCell = screen.getByText('Іван').closest('td');
    const phoneCell = screen.getByText('+380').closest('td');
    expect(nameCell).toHaveClass('table-mobile-title-text');
    expect(phoneCell).toHaveClass('table-mobile-hidden-cell');
    const actionsCell = screen.getByText('Редагувати').closest('td');
    expect(actionsCell).toHaveClass('table-actions-cell');
  });

  it('cardTop → table-card-top-cell, mobile:"secondary" → table-mobile-secondary, mobile:"trailing" → table-mobile-trailing', () => {
    const cols = [
      { key: 'name', label: "Ім'я" },
      { key: 'status', label: 'Статус', cardTop: true },
      { key: 'plan', label: 'Тариф', mobile: 'secondary' },
      { key: 'date', label: 'Дата', mobile: 'trailing' },
    ];
    render(<Table columns={cols} rows={[{ id: 1, name: 'Іван', status: 'active', plan: 'Pro', date: '01.01' }]} />);
    expect(screen.getByText('active').closest('td')).toHaveClass('table-card-top-cell');
    expect(screen.getByText('Pro').closest('td')).toHaveClass('table-mobile-secondary');
    expect(screen.getByText('01.01').closest('td')).toHaveClass('table-mobile-trailing');
  });

  it('використовує render() колонки, коли він заданий', () => {
    render(<Table columns={columns} rows={[{ id: 1, name: 'Іван', phone: '+380' }]} />);
    expect(screen.getByRole('button', { name: 'Редагувати' })).toBeInTheDocument();
  });

  it('клік по рядку викликає onRowClick з даними рядка', () => {
    const onRowClick = vi.fn();
    const row = { id: 42, name: 'Іван', phone: '+380' };
    render(<Table columns={columns} rows={[row]} onRowClick={onRowClick} />);
    fireEvent.click(screen.getByText('Іван').closest('tr'));
    expect(onRowClick).toHaveBeenCalledWith(row);
  });

  it('rowProps додає className і style до рядка', () => {
    const row = { id: 1, name: 'Іван', phone: '+380' };
    render(
      <Table
        columns={columns}
        rows={[row]}
        rowProps={() => ({ className: 'archived', style: { color: 'red' } })}
      />
    );
    const tr = screen.getByText('Іван').closest('tr');
    expect(tr).toHaveClass('archived');
    expect(tr).toHaveStyle({ color: 'rgb(255, 0, 0)' });
  });
});
