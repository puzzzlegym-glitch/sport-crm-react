import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import PhoneInput, { extractPhoneDigits, isValidPhone } from './PhoneInput';

describe('extractPhoneDigits', () => {
  it('прибирає код +380', () => {
    expect(extractPhoneDigits('+380671234567')).toBe('671234567');
  });
  it('розпізнає локальний формат 0XXXXXXXXX', () => {
    expect(extractPhoneDigits('0671234567')).toBe('671234567');
  });
  it('обрізає до 9 цифр', () => {
    expect(extractPhoneDigits('+3806712345678888')).toBe('671234567');
  });
  it('порожній рядок для порожнього значення', () => {
    expect(extractPhoneDigits('')).toBe('');
  });
});

describe('isValidPhone', () => {
  it('порожній телефон вважається валідним (не обов\'язкове поле)', () => {
    expect(isValidPhone('')).toBe(true);
  });
  it('валідний повний номер', () => {
    expect(isValidPhone('+380671234567')).toBe(true);
  });
  it('невалідний — замало цифр', () => {
    expect(isValidPhone('+38067123')).toBe(false);
  });
});

describe('PhoneInput', () => {
  it('показує префікс +380 і форматує введені цифри групами', () => {
    render(<PhoneInput value="" onChange={() => {}} />);
    expect(screen.getByText('+380')).toBeInTheDocument();
    const input = screen.getByPlaceholderText('XX XXX XX XX');
    fireEvent.change(input, { target: { value: '671234567' } });
  });

  it('викликає onChange із нормалізованим значенням +380XXXXXXXXX', () => {
    let received;
    render(<PhoneInput value="" onChange={(v) => { received = v; }} />);
    const input = screen.getByPlaceholderText('XX XXX XX XX');
    fireEvent.change(input, { target: { value: '671234567' } });
    expect(received).toBe('+380671234567');
  });

  it('відображає раніше збережений номер у форматованому вигляді', () => {
    render(<PhoneInput value="+380671234567" onChange={() => {}} />);
    expect(screen.getByDisplayValue('67 123 45 67')).toBeInTheDocument();
  });
});
