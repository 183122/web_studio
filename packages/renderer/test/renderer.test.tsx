// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { PageRenderer, findPageByPath, resolveStyles, toCss } from '../src/index.ts';
import { sampleDoc } from '../../app-schema/test/fixture-doc.ts';
import type { AppDefinition } from '@studio/app-schema';

describe('styles', () => {
  it('cascades desktop → tablet → mobile', () => {
    const s = { base: { fontSize: '40px', color: '#111111' }, tablet: { fontSize: '32px' }, mobile: { fontSize: '24px' } };
    expect(resolveStyles(s, 'base')).toEqual({ fontSize: '40px', color: '#111111' });
    expect(resolveStyles(s, 'tablet').fontSize).toBe('32px');
    expect(resolveStyles(s, 'mobile').fontSize).toBe('24px');
  });

  it('resolves tokens, columns and drops unknown properties', () => {
    const doc = sampleDoc();
    const css = toCss({ color: '$primary', borderRadius: '$radius', columns: 3, position: 'fixed' } as never, doc.designTokens);
    expect(css).toEqual({ color: '#0f766e', borderRadius: '12px', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' });
  });
});

describe('PageRenderer', () => {
  it('renders text as text (no HTML injection) and marks nodes in edit mode', () => {
    const doc = sampleDoc();
    doc.components.c_heading.props.text = '<img src=x onerror=alert(1)>';
    const { container } = render(<PageRenderer doc={doc} pageId="pg_home" breakpoint="base" mode="edit" />);
    expect(container.querySelector('h1')?.textContent).toBe('<img src=x onerror=alert(1)>');
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-node-id="c_btn"]')?.tagName).toBe('A');
  });

  it('neutralises unsafe hrefs even if a document bypassed validation', () => {
    const doc = sampleDoc() as AppDefinition;
    doc.components.c_btn.props.href = 'javascript:alert(1)';
    const { container } = render(<PageRenderer doc={doc} pageId="pg_home" breakpoint="base" mode="live" />);
    expect(container.querySelector('a')?.getAttribute('href')).toBe('#');
  });

  it('resolves asset references through the provided resolver and omits data attributes in live mode', () => {
    const doc = sampleDoc();
    const { container } = render(
      <PageRenderer doc={doc} pageId="pg_about" breakpoint="mobile" mode="live" resolveAsset={(ref) => `/signed/${ref.slice(6)}`} />,
    );
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/signed/01HZZZZZZZZZZZZZZZZZZZZZZZ');
    expect(container.querySelector('[data-node-id]')).toBeNull();
  });

  it('applies breakpoint styles', () => {
    const doc = sampleDoc();
    const { container } = render(<PageRenderer doc={doc} pageId="pg_home" breakpoint="mobile" mode="live" />);
    expect((container.querySelector('h1') as HTMLElement).style.fontSize).toBe('28px');
  });

  it('finds pages by path and falls back to the 404 page', () => {
    const doc = sampleDoc();
    expect(findPageByPath(doc, '/about?x=1')).toBe('pg_about');
    expect(findPageByPath(doc, '/missing')).toBeNull();
    doc.settings.notFoundPageId = 'pg_about';
    expect(findPageByPath(doc, '/missing')).toBe('pg_about');
  });
});
