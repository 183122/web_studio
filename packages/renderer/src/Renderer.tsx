import { createContext, useContext, useState, type CSSProperties, type MouseEvent, type ReactNode } from 'react';
import { getComponentDefinition, type Breakpoint } from '@studio/component-registry';
import { isSafeHref, isSafeImageSource, PATTERNS, type AppDefinition, type ComponentNode } from '@studio/app-schema';
import { resolveStyles, toCss, fontStack } from './styles.ts';
import { DataTableView, FormFieldView, FormView, type DataHost } from './data.tsx';

export type RenderMode = 'edit' | 'preview' | 'live';

export interface RenderOptions {
  doc: AppDefinition;
  breakpoint: Breakpoint;
  mode: RenderMode;
  /** Maps "asset:<id>" to a (signed) URL. Return undefined while loading. */
  resolveAsset?: (ref: string) => string | undefined;
  /** Preview mode: called for internal links instead of navigating the browser. */
  onNavigate?: (href: string) => void;
  /** Data access for forms and tables (preview: preview-runtime API; live: Phase 4 runtime). */
  dataHost?: DataHost;
}

export const Ctx = createContext<RenderOptions | null>(null);
export const useRender = () => {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('Renderer context missing');
  return ctx;
};

const HEADING_TAGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
const SECTION_TAGS = new Set(['section', 'header', 'footer', 'main', 'div']);

const str = (v: unknown, fallback = ''): string => (typeof v === 'string' ? v : fallback);
const safeHref = (v: unknown): string => (typeof v === 'string' && isSafeHref(v) ? v : '#');

function useImageSrc(value: unknown): string | undefined {
  const { resolveAsset } = useRender();
  if (typeof value !== 'string' || value === '' || !isSafeImageSource(value)) return undefined;
  if (PATTERNS.assetRef.test(value)) return resolveAsset?.(value);
  return value;
}

function Placeholder({ label, style }: { label: string; style?: CSSProperties }) {
  return (
    <div
      style={{
        display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 120,
        border: '2px dashed #94a3b8', borderRadius: 8, color: '#64748b', fontSize: 14,
        background: 'repeating-linear-gradient(45deg,#f8fafc,#f8fafc 10px,#f1f5f9 10px,#f1f5f9 20px)', ...style,
      }}
    >
      {label}
    </div>
  );
}

function buttonVariantStyle(variant: string, primary: string): CSSProperties {
  const base: CSSProperties = {
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
    padding: '12px 22px', borderRadius: 999, fontWeight: 600, fontSize: 16, textDecoration: 'none',
    border: '2px solid transparent', cursor: 'pointer', lineHeight: 1.2, width: 'fit-content', maxWidth: '100%',
  };
  switch (variant) {
    case 'secondary':
      return { ...base, background: '#ffffff', color: primary };
    case 'outline':
      return { ...base, background: 'transparent', color: 'inherit', borderColor: 'currentColor' };
    case 'link':
      return { ...base, padding: '4px 0', background: 'transparent', color: primary, textDecoration: 'underline', borderRadius: 0 };
    default:
      return { ...base, background: primary, color: '#ffffff' };
  }
}

function Link({ href, newTab, style, children, nodeAttrs }: { href: string; newTab?: boolean; style: CSSProperties; children: ReactNode; nodeAttrs?: Record<string, string> }) {
  const { mode, onNavigate } = useRender();
  const onClick = (e: MouseEvent<HTMLAnchorElement>) => {
    if (mode === 'edit') {
      e.preventDefault();
      return;
    }
    if (mode === 'preview' && onNavigate && (href.startsWith('/') || href.startsWith('#'))) {
      e.preventDefault();
      onNavigate(href);
    }
  };
  return (
    <a
      href={href}
      onClick={onClick}
      style={style}
      {...(newTab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      {...nodeAttrs}
    >
      {children}
    </a>
  );
}

function Navbar({ node, css, attrs }: { node: ComponentNode; css: CSSProperties; attrs: Record<string, string> }) {
  const { doc, breakpoint } = useRender();
  const [open, setOpen] = useState(false);
  const logo = useImageSrc(node.props.logo);
  const links = Array.isArray(node.props.links) ? (node.props.links as Array<{ label: unknown; href: unknown }>) : [];
  const compact = breakpoint === 'mobile';
  const linkList = (
    <ul style={{ display: 'flex', flexDirection: compact ? 'column' : 'row', gap: compact ? 8 : 24, listStyle: 'none', margin: 0, padding: 0 }}>
      {links.map((l, i) => (
        <li key={i}>
          <Link href={safeHref(l.href)} style={{ color: 'inherit', textDecoration: 'none', fontWeight: 500 }}>
            {str(l.label)}
          </Link>
        </li>
      ))}
    </ul>
  );
  return (
    <nav {...attrs} style={{ ...css }} aria-label={str(node.props.brand, 'เมนูหลัก')}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, maxWidth: 1120, margin: '0 auto' }}>
        <span style={{ display: 'flex', alignItems: 'center', gap: 10, fontFamily: fontStack(doc.designTokens.fonts.heading), fontWeight: 700, fontSize: 20 }}>
          {logo ? <img src={logo} alt="" style={{ height: 32, width: 'auto' }} /> : null}
          {str(node.props.brand)}
        </span>
        {compact ? (
          <button
            type="button"
            aria-expanded={open}
            aria-label={open ? 'ปิดเมนู' : 'เปิดเมนู'}
            onClick={() => setOpen((o) => !o)}
            style={{ background: 'transparent', border: '1px solid currentColor', borderRadius: 8, padding: '6px 10px', color: 'inherit', cursor: 'pointer' }}
          >
            ☰
          </button>
        ) : (
          linkList
        )}
      </div>
      {compact && open ? <div style={{ marginTop: 12 }}>{linkList}</div> : null}
    </nav>
  );
}

function NodeView({ id }: { id: string }): ReactNode {
  const ctx = useRender();
  const { doc, breakpoint, mode } = ctx;
  const node = doc.components[id];
  const def = node ? getComponentDefinition(node.type) : undefined;
  const bgImage = useImageSrc(node?.props.backgroundImage);
  const imageSrc = useImageSrc(node?.props.src);
  if (!node || !def) {
    return mode === 'edit' ? <Placeholder label={`คอมโพเนนต์ไม่รองรับ: ${node?.type ?? id}`} /> : null;
  }

  const css = toCss(resolveStyles(node.styles, breakpoint), doc.designTokens);
  const attrs: Record<string, string> = mode === 'edit' ? { 'data-node-id': node.id, 'data-node-type': node.type } : {};
  const children = node.children.map((c) => <NodeView key={c} id={c} />);
  const emptyDrop = mode === 'edit' && def.acceptsChildren && node.children.length === 0
    ? <Placeholder label="ลากคอมโพเนนต์มาวางที่นี่" style={{ minHeight: 80, width: '100%' }} />
    : null;

  switch (node.type) {
    case 'Page':
      return (
        <div
          {...attrs}
          style={{
            backgroundColor: doc.designTokens.colors.background,
            color: doc.designTokens.colors.text,
            fontFamily: fontStack(doc.designTokens.fonts.body),
            minHeight: '100%',
            ...css,
          }}
        >
          {children}
          {emptyDrop}
        </div>
      );
    case 'Section': {
      const Tag = (SECTION_TAGS.has(str(node.props.tag)) ? str(node.props.tag) : 'section') as 'section';
      const anchor = str(node.props.anchorId);
      const style: CSSProperties = { ...css };
      if (bgImage) {
        style.backgroundImage = `url(${JSON.stringify(bgImage)})`;
        style.backgroundSize = 'cover';
        style.backgroundPosition = 'center';
      }
      return (
        <Tag {...attrs} id={anchor || undefined} style={style}>
          {children}
          {emptyDrop}
        </Tag>
      );
    }
    case 'Container':
    case 'Columns':
    case 'Card':
      return (
        <div {...attrs} style={css}>
          {children}
          {emptyDrop}
        </div>
      );
    case 'Heading': {
      const Tag = (HEADING_TAGS.has(str(node.props.level)) ? str(node.props.level) : 'h2') as 'h2';
      return (
        <Tag {...attrs} style={{ margin: 0, ...css }}>
          {str(node.props.text)}
        </Tag>
      );
    }
    case 'Text':
      return (
        <p {...attrs} style={{ margin: 0, whiteSpace: 'pre-line', ...css }}>
          {str(node.props.text)}
        </p>
      );
    case 'Button': {
      const style = { ...buttonVariantStyle(str(node.props.variant, 'primary'), doc.designTokens.colors.primary), ...css };
      return (
        <Link href={safeHref(node.props.href)} newTab={node.props.newTab === true} style={style} nodeAttrs={attrs}>
          {str(node.props.label)}
        </Link>
      );
    }
    case 'Image':
      if (!imageSrc) {
        return mode === 'edit' ? <div {...attrs} style={css}><Placeholder label="เลือกรูปภาพในแผงคุณสมบัติ" /></div> : null;
      }
      return <img {...attrs} src={imageSrc} alt={str(node.props.alt)} loading="lazy" style={{ display: 'block', maxWidth: '100%', ...css }} />;
    case 'Video': {
      const vid = str(node.props.videoId);
      if (!PATTERNS.videoId.test(vid)) {
        return mode === 'edit' ? <div {...attrs} style={css}><Placeholder label="ใส่ Video ID ในแผงคุณสมบัติ" /></div> : null;
      }
      const src = node.props.provider === 'vimeo'
        ? `https://player.vimeo.com/video/${encodeURIComponent(vid)}`
        : `https://www.youtube-nocookie.com/embed/${encodeURIComponent(vid)}`;
      return (
        <div {...attrs} style={{ position: 'relative', aspectRatio: '16 / 9', overflow: 'hidden', ...css }}>
          <iframe
            src={src}
            title={str(node.props.title, 'วิดีโอ')}
            loading="lazy"
            allow="encrypted-media; picture-in-picture; fullscreen"
            sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
            referrerPolicy="strict-origin-when-cross-origin"
            style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', border: 0, pointerEvents: mode === 'edit' ? 'none' : 'auto' }}
          />
        </div>
      );
    }
    case 'Navbar':
      return <Navbar node={node} css={css} attrs={attrs} />;
    case 'List': {
      const items = Array.isArray(node.props.items) ? (node.props.items as unknown[]) : [];
      const Tag = node.props.ordered === true ? 'ol' : 'ul';
      return (
        <Tag {...attrs} style={{ margin: 0, paddingLeft: 24, ...css }}>
          {items.map((item, i) => <li key={i}>{str(item)}</li>)}
        </Tag>
      );
    }
    case 'Divider':
      return <hr {...attrs} style={{ border: 0, borderTopStyle: 'solid', borderTopWidth: 1, ...css, height: 0 }} />;
    case 'Form':
      return <FormView node={node} css={css} attrs={attrs}>{children}{emptyDrop}</FormView>;
    case 'FormField':
      return <FormFieldView node={node} css={css} attrs={attrs} />;
    case 'DataTable':
      return <DataTableView node={node} css={css} attrs={attrs} />;
    case 'Spacer':
      return <div {...attrs} aria-hidden="true" style={css} />;
    default:
      return mode === 'edit' ? <Placeholder label={`คอมโพเนนต์ไม่รองรับ: ${node.type}`} /> : null;
  }
}

export interface PageRendererProps extends RenderOptions {
  pageId: string;
}

/** Renders one page of an AppDefinition using only allowlisted components (no HTML injection). */
export function PageRenderer({ pageId, ...options }: PageRendererProps) {
  const page = options.doc.pages.find((p) => p.id === pageId);
  if (!page) return null;
  return (
    <Ctx.Provider value={options}>
      <NodeView id={page.rootId} />
    </Ctx.Provider>
  );
}

/** Find the page for a path (exact match), falling back to the configured 404 page or null. */
export function findPageByPath(doc: AppDefinition, path: string): string | null {
  const clean = path.split(/[?#]/)[0] || '/';
  const page = doc.pages.find((p) => p.path === clean);
  if (page) return page.id;
  return doc.settings.notFoundPageId ?? null;
}
