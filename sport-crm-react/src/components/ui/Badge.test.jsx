import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import Badge from './Badge';

describe('Badge', () => {
  it('застосовує клас badge-{variant}', () => {
    render(<Badge variant="active">Активний</Badge>);
    expect(screen.getByText('Активний')).toHaveClass('badge', 'badge-active');
  });

  it('дефолтний variant — info', () => {
    render(<Badge>Щось</Badge>);
    expect(screen.getByText('Щось')).toHaveClass('badge-info');
  });
});
