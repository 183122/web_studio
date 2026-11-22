// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { PageRenderer, type DataHost } from '../src/index.ts';
import { sampleDoc } from '../../app-schema/test/fixture-doc.ts';

function host(overrides: Partial<DataHost> = {}): DataHost {
  return {
    submit: vi.fn(async () => ({ ok: true as const })),
    list: vi.fn(async () => ({ rows: [{ id: 'r1', data: { f_name: 'สมชาย', f_status: 'pending' } }], total: 1, lastPage: 1 })),
    ...overrides,
  };
}

afterEach(cleanup);

describe('data components', () => {
  it('validates on the client and submits normalised values with spam metadata', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const h = host();
    render(<PageRenderer doc={sampleDoc()} pageId="pg_about" breakpoint="base" mode="preview" dataHost={h} />);
    fireEvent.click(screen.getByRole('button', { name: 'สมัคร' }));
    expect(await screen.findAllByRole('alert')).toHaveLength(2); // name + email required
    expect(h.submit).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/ชื่อ–นามสกุล/), { target: { value: '  สมหญิง  ' } });
    fireEvent.change(screen.getByLabelText(/อีเมล/), { target: { value: 'Som@Example.com' } });
    vi.advanceTimersByTime(2000);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'สมัคร' })));
    expect(h.submit).toHaveBeenCalledWith('m_reg', { f_name: 'สมหญิง', f_email: 'som@example.com' }, expect.objectContaining({ hp: '' }));
    expect((h.submit as ReturnType<typeof vi.fn>).mock.calls[0][2].elapsedMs).toBeGreaterThanOrEqual(2000);
    expect((await screen.findByRole('status')).textContent).toContain('ขอบคุณ');
    vi.useRealTimers();
  });

  it('shows server field errors', async () => {
    const h = host({ submit: vi.fn(async () => ({ ok: false as const, message: 'ข้อมูลไม่ถูกต้อง', fieldErrors: { f_email: 'already exists' } })) });
    render(<PageRenderer doc={sampleDoc()} pageId="pg_about" breakpoint="base" mode="preview" dataHost={h} />);
    fireEvent.change(screen.getByLabelText(/ชื่อ–นามสกุล/), { target: { value: 'A' } });
    fireEvent.change(screen.getByLabelText(/อีเมล/), { target: { value: 'a@example.com' } });
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'สมัคร' })));
    expect(await screen.findByText('ข้อมูลนี้มีอยู่แล้วในระบบ')).toBeTruthy();
  });

  it('renders table rows from the data host and sample rows in edit mode', async () => {
    const h = host();
    const { unmount } = render(<PageRenderer doc={sampleDoc()} pageId="pg_about" breakpoint="base" mode="preview" dataHost={h} />);
    await waitFor(() => expect(screen.getByText('สมชาย')).toBeTruthy());
    expect(h.list).toHaveBeenCalledWith('m_reg', { page: 1, perPage: 10, search: '' });
    unmount();
    render(<PageRenderer doc={sampleDoc()} pageId="pg_about" breakpoint="base" mode="edit" />);
    expect(screen.getAllByText(/ตัวอย่าง ชื่อ–นามสกุล/)).toHaveLength(3);
    expect(document.querySelector('[data-node-id="c_form"]')?.tagName).toBe('DIV');
  });
});
