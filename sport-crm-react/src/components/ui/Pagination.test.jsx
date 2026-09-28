import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Pagination from './Pagination';

describe('Pagination (1:1 з renderPagination() у clients.php)', () => {
  it('нічого не рендерить, якщо сторінка одна', () => {
    const { container } = render(<Pagination page={1} pages={1} total={5} perPage={20} onChange={vi.fn()} />);
    expect(container.firstChild).toBeNull();
  });

  it('показує діапазон "from–to з total"', () => {
    render(<Pagination page={2} pages={5} total={97} perPage={20} onChange={vi.fn()} />);
    expect(screen.getByText('21–40 з 97')).toBeInTheDocument();
  });

  it('кнопка "назад" вимкнена на першій сторінці, "вперед" — на останній', () => {
    render(<Pagination page={1} pages={3} total={60} perPage={20} onChange={vi.fn()} />);
    expect(screen.getByText('‹')).toBeDisabled();
    expect(screen.getByText('›')).not.toBeDisabled();
  });

  it('клік по номеру сторінки викликає onChange з цим номером', () => {
    const onChange = vi.fn();
    render(<Pagination page={1} pages={3} total={60} perPage={20} onChange={onChange} />);
    fireEvent.click(screen.getByText('3'));
    expect(onChange).toHaveBeenCalledWith(3);
  });

  it('показує "…" для віддалених сторінок (delta=2)', () => {
    render(<Pagination page={1} pages={10} total={200} perPage={20} onChange={vi.fn()} />);
    expect(screen.getByText('…')).toBeInTheDocument();
  });
});
