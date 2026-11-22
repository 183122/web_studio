/**
 * Builds curated templates into dist/*.json using deterministic ids, validating each with
 * the canonical validator. `--check` validates without writing (used in CI).
 * Imported by apps/platform-api (TemplateSeeder).
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { canonicalize, collectAssetRefs, DEFAULT_TOKENS, validateDefinition } from '../app-schema/src/index.ts';
import type { AppDefinition, ComponentNode, DesignTokens, PageDefinition } from '../app-schema/src/index.ts';
import { defaultPropsFor, getComponentDefinition } from '../component-registry/src/index.ts';

type Styles = ComponentNode['styles'];
interface Spec {
  type: string;
  props?: Record<string, unknown>;
  styles?: Styles;
  name?: string;
  children?: Spec[];
}

const el = (type: string, props: Record<string, unknown> = {}, styles?: Styles, children: Spec[] = [], name?: string): Spec => ({ type, props, styles, children, name });

class Builder {
  components: Record<string, ComponentNode> = {};
  pages: PageDefinition[] = [];
  private counter = 0;
  private readonly prefix: string;
  constructor(prefix: string) {
    this.prefix = prefix;
  }

  private add(spec: Spec): string {
    const def = getComponentDefinition(spec.type);
    if (!def) throw new Error(`unknown type ${spec.type}`);
    const id = `${spec.type === 'Page' ? 'p' : 'c'}_${this.prefix}${(++this.counter).toString(36).padStart(3, '0')}`;
    const styles: Styles = structuredClone(spec.styles ?? def.defaultStyles);
    for (const bp of Object.keys(styles) as Array<keyof Styles>) {
      if (!styles[bp] || Object.keys(styles[bp]!).length === 0) delete styles[bp];
    }
    const node: ComponentNode = {
      id,
      type: spec.type,
      props: { ...defaultPropsFor(spec.type), ...(spec.props ?? {}) },
      styles,
      children: [],
      ...(spec.name ? { name: spec.name } : {}),
    };
    this.components[id] = node;
    node.children = (spec.children ?? []).map((c) => this.add(c));
    return id;
  }

  page(name: string, path: string, title: string, description: string, children: Spec[]): string {
    const rootId = this.add({ type: 'Page', children });
    const id = `pg_${this.prefix}${this.pages.length + 1}`;
    this.pages.push({ id, name, path, rootId, seo: { title, description, noindex: false } });
    return id;
  }

  build(name: string, description: string, tokens: DesignTokens, data: Pick<AppDefinition, 'dataModels' | 'permissions' | 'workflows'> = { dataModels: [], permissions: [], workflows: [] }): AppDefinition {
    const doc: AppDefinition = {
      schemaVersion: 2,
      projectId: 'template',
      name,
      pages: this.pages,
      routes: [],
      components: this.components,
      designTokens: tokens,
      dataModels: data.dataModels,
      permissions: data.permissions,
      actions: [],
      workflows: data.workflows,
      integrations: [],
      assets: [],
      seo: { siteName: name, description, lang: 'th' },
      settings: { homePageId: this.pages[0].id, notFoundPageId: null },
    };
    doc.assets = collectAssetRefs(doc);
    return doc;
  }
}

// ---------- shared blocks ----------
const navbar = (brand: string, links: Array<[string, string]>) =>
  el('Navbar', { brand, links: links.map(([label, href]) => ({ label, href })) }, { base: { padding: '16px 24px', backgroundColor: '$surface' } });

const container = (children: Spec[], extra: Styles['base'] = {}) =>
  el('Container', {}, { base: { display: 'flex', flexDirection: 'column', gap: '16px', maxWidth: '1120px', margin: '0 auto', width: '100%', ...extra } }, children);

const section = (children: Spec[], base: Styles['base'] = {}, name?: string, anchorId = '') =>
  el('Section', { tag: 'section', anchorId, backgroundImage: '' }, { base: { padding: '64px 24px', ...base }, mobile: { padding: '40px 16px' } }, children, name);

const heading = (text: string, level = 'h2', base: Styles['base'] = {}, mobileSize?: string) =>
  el('Heading', { text, level }, { base: { fontFamily: '$heading', fontWeight: '700', fontSize: level === 'h1' ? '44px' : level === 'h2' ? '32px' : '22px', lineHeight: 1.3, ...base }, mobile: { fontSize: mobileSize ?? (level === 'h1' ? '30px' : level === 'h2' ? '26px' : '20px') } });

const text = (t: string, base: Styles['base'] = {}) => el('Text', { text: t }, { base: { fontSize: '17px', lineHeight: 1.7, ...base } });
const button = (label: string, href: string, variant = 'primary') => el('Button', { label, href, variant, newTab: false });
const card = (children: Spec[]) => el('Card', {}, undefined, children);
const columns = (n: number, children: Spec[]) =>
  el('Columns', {}, { base: { display: 'grid', columns: n, gap: '24px' }, tablet: { columns: Math.min(n, 2) }, mobile: { columns: 1 } }, children);
const ff = (fieldId: string, placeholder: string) => el('FormField', { fieldId, label: '', placeholder, helpText: '' });
const footer = (line: string) =>
  el('Section', { tag: 'footer', anchorId: '', backgroundImage: '' }, { base: { padding: '32px 24px', backgroundColor: '$secondary', color: '#ffffff' } }, [container([text(line, { fontSize: '14px', textAlign: 'center' })])]);
const buttons = (items: Spec[]) => el('Container', {}, { base: { display: 'flex', flexDirection: 'row', flexWrap: 'wrap', gap: '12px' } }, items);

// ---------- 1. Training registration (MVP showcase) ----------
function trainingRegistration() {
  const b = new Builder('tr');
  const nav = () => navbar('ศูนย์ฝึกอบรมออนไลน์', [['หน้าแรก', '/'], ['หลักสูตร', '/courses'], ['ลงทะเบียน', '/register'], ['ติดต่อ', '/#contact']]);
  b.page('หน้าแรก', '/', 'ระบบลงทะเบียนอบรม', 'ลงทะเบียนเข้าร่วมอบรมออนไลน์ได้ง่าย ๆ ภายในไม่กี่นาที', [
    nav(),
    section([
      container([
        heading('พัฒนาทักษะใหม่ กับหลักสูตรอบรมคุณภาพ', 'h1', { color: '#ffffff' }),
        text('เลือกหลักสูตรที่สนใจ ลงทะเบียนออนไลน์ และรับใบประกาศนียบัตรเมื่อผ่านการอบรม', { color: '#e2f3f1', fontSize: '19px' }),
        buttons([button('ดูหลักสูตรทั้งหมด', '/courses', 'secondary'), button('ลงทะเบียนเลย', '/register', 'outline')]),
      ], { maxWidth: '880px' }),
    ], { backgroundColor: '$primary', padding: '96px 24px' }, 'Hero'),
    section([
      container([
        heading('ทำไมต้องอบรมกับเรา', 'h2', { textAlign: 'center' }),
        columns(3, [
          card([heading('วิทยากรผู้เชี่ยวชาญ', 'h3'), text('ทีมวิทยากรที่มีประสบการณ์ตรงในแต่ละสาขา')]),
          card([heading('เรียนได้ทุกที่', 'h3'), text('อบรมทั้งแบบออนไลน์และในสถานที่ เลือกได้ตามความสะดวก')]),
          card([heading('ใบประกาศนียบัตร', 'h3'), text('รับใบประกาศนียบัตรเมื่อผ่านเกณฑ์การอบรม')]),
        ]),
      ]),
    ], {}, 'Benefits'),
    section([
      container([
        heading('ขั้นตอนการลงทะเบียน', 'h2'),
        el('List', { items: ['เลือกหลักสูตรที่ต้องการ', 'กรอกข้อมูลผู้สมัคร', 'รอรับอีเมลยืนยันการลงทะเบียน', 'เข้าร่วมอบรมตามวันเวลาที่กำหนด'], ordered: true }, { base: { fontSize: '17px', lineHeight: 1.9 } }),
        buttons([button('เริ่มลงทะเบียน', '/register')]),
      ], { maxWidth: '880px' }),
    ], { backgroundColor: '$surface' }, 'Steps'),
    section([
      container([
        heading('ติดต่อเรา', 'h2'),
        text('อีเมล: training@example.com\nโทร: 02-000-0000 (จันทร์–ศุกร์ 08:30–16:30 น.)'),
        buttons([button('ส่งอีเมลถึงเรา', 'mailto:training@example.com', 'outline')]),
      ], { maxWidth: '880px' }),
    ], {}, 'Contact', 'contact'),
    footer('© ศูนย์ฝึกอบรมออนไลน์ — สร้างด้วย AI Studio Cloud'),
  ]);
  const course = (title: string, date: string, desc: string) =>
    card([heading(title, 'h3'), text(date, { color: '$muted', fontSize: '15px' }), text(desc), button('ลงทะเบียนหลักสูตรนี้', '/register', 'link')]);
  b.page('หลักสูตร', '/courses', 'หลักสูตรอบรมทั้งหมด', 'รายการหลักสูตรอบรมที่เปิดรับสมัคร', [
    nav(),
    section([
      container([
        heading('หลักสูตรที่เปิดรับสมัคร', 'h1'),
        text('เลือกหลักสูตรที่ตรงกับเป้าหมายของคุณ'),
        columns(3, [
          course('การใช้ AI เพื่อการทำงาน', 'อบรม 1 วัน • ออนไลน์', 'เรียนรู้การใช้เครื่องมือ AI เพื่อเพิ่มประสิทธิภาพงานประจำวัน'),
          course('การวิเคราะห์ข้อมูลเบื้องต้น', 'อบรม 2 วัน • ในสถานที่', 'พื้นฐานการจัดการและวิเคราะห์ข้อมูลด้วยสเปรดชีต'),
          course('การออกแบบเว็บไซต์ไม่ต้องเขียนโค้ด', 'อบรม 1 วัน • ออนไลน์', 'สร้างเว็บไซต์องค์กรด้วยเครื่องมือ No-code อย่างมืออาชีพ'),
        ]),
      ]),
    ]),
    footer('© ศูนย์ฝึกอบรมออนไลน์'),
  ]);
  b.page('ลงทะเบียน', '/register', 'ลงทะเบียนอบรม', 'ลงทะเบียนเข้าร่วมอบรม', [
    nav(),
    section([
      container([
        heading('ลงทะเบียนเข้าร่วมอบรม', 'h1'),
        text('กรุณาเตรียมข้อมูลต่อไปนี้: ชื่อ–นามสกุล อีเมล เบอร์โทรศัพท์ หน่วยงาน และหลักสูตรที่ต้องการ'),
        el('Form', { modelId: 'm_registrations', submitLabel: 'ส่งใบสมัคร', successMessage: 'ได้รับใบสมัครแล้ว ระบบได้ส่งอีเมลยืนยันให้คุณ' }, undefined, [
          ff('f_reg_name', 'เช่น สมชาย ใจดี'),
          ff('f_reg_email', 'name@example.com'),
          ff('f_reg_phone', '08x-xxx-xxxx'),
          ff('f_reg_org', ''),
          ff('f_reg_course', ''),
          ff('f_reg_note', ''),
        ], 'ฟอร์มลงทะเบียน'),
        text('ข้อมูลของคุณใช้เพื่อการจัดอบรมเท่านั้น ตามนโยบายความเป็นส่วนตัว (PDPA)', { fontSize: '14px', color: '$muted' }),
      ], { maxWidth: '760px' }),
    ]),
    footer('© ศูนย์ฝึกอบรมออนไลน์'),
  ]);
  const regs = b.page('รายชื่อผู้สมัคร', '/registrants', 'รายชื่อผู้สมัคร (ผู้ดูแล)', 'สำหรับผู้ดูแลการอบรม', [
    nav(),
    section([
      container([
        heading('รายชื่อผู้สมัคร', 'h1'),
        text('หน้านี้แสดงข้อมูลเฉพาะผู้ที่มีสิทธิ์อ่าน (ผู้ดูแลแอป) — ลองเปลี่ยนบทบาทในหน้าดูตัวอย่าง', { color: '$muted' }),
        el('DataTable', { modelId: 'm_registrations', fieldIds: ['f_reg_name', 'f_reg_course', 'f_reg_status'], pageSize: '10', searchable: true, emptyMessage: 'ยังไม่มีผู้สมัคร' }),
      ]),
    ]),
    footer('© ศูนย์ฝึกอบรมออนไลน์'),
  ]);
  b.pages.find((p) => p.id === regs)!.seo.noindex = true;
  const courses = ['การใช้ AI เพื่อการทำงาน', 'การวิเคราะห์ข้อมูลเบื้องต้น', 'การออกแบบเว็บไซต์ไม่ต้องเขียนโค้ด'];
  const data = {
    dataModels: [{
      id: 'm_registrations', name: 'registrations', label: 'ผู้สมัครอบรม', displayFieldId: 'f_reg_name',
      fields: [
        { id: 'f_reg_name', name: 'full_name', label: 'ชื่อ–นามสกุล', type: 'text' as const, required: true, unique: false, maxLength: 120 },
        { id: 'f_reg_email', name: 'email', label: 'อีเมล', type: 'email' as const, required: true, unique: true },
        { id: 'f_reg_phone', name: 'phone', label: 'เบอร์โทรศัพท์', type: 'phone' as const, required: false, unique: false },
        { id: 'f_reg_org', name: 'organization', label: 'หน่วยงาน', type: 'text' as const, required: false, unique: false, maxLength: 160 },
        { id: 'f_reg_course', name: 'course', label: 'หลักสูตร', type: 'select' as const, required: true, unique: false, options: courses },
        { id: 'f_reg_note', name: 'note', label: 'หมายเหตุ', type: 'longtext' as const, required: false, unique: false, maxLength: 1000 },
        { id: 'f_reg_status', name: 'status', label: 'สถานะ', type: 'select' as const, required: false, unique: false, options: ['รอตรวจสอบ', 'ยืนยันแล้ว', 'ยกเลิก'] },
      ],
    }],
    permissions: [{ modelId: 'm_registrations', rules: { public: ['create' as const], member: [], admin: ['create' as const, 'read' as const, 'update' as const, 'delete' as const] } }],
    workflows: [{
      id: 'w_reg_received', name: 'ยืนยันการรับใบสมัคร', enabled: true,
      trigger: { event: 'record.created' as const, modelId: 'm_registrations' },
      conditions: [],
      actions: [
        { type: 'record.setField' as const, fieldId: 'f_reg_status', value: 'รอตรวจสอบ' },
        { type: 'email.send' as const, to: 'field:f_reg_email', subject: 'ได้รับใบสมัครหลักสูตร {{course}} แล้ว', body: 'เรียน คุณ{{full_name}}\n\nศูนย์ฝึกอบรมได้รับใบสมัครหลักสูตร "{{course}}" ของคุณแล้ว เจ้าหน้าที่จะยืนยันภายใน 1 วันทำการ\n\nศูนย์ฝึกอบรมออนไลน์' },
        { type: 'email.send' as const, to: 'training@example.com', subject: 'ผู้สมัครใหม่: {{full_name}}', body: 'ผู้สมัคร: {{full_name}} ({{email}})\nหลักสูตร: {{course}}\nหน่วยงาน: {{organization}}' },
      ],
    }],
  };
  return {
    slug: 'training-registration',
    name: 'ระบบลงทะเบียนอบรม',
    description: 'ศูนย์ฝึกอบรม: หลักสูตร ฟอร์มลงทะเบียนที่บันทึกลงฐานข้อมูล อีเมลยืนยันอัตโนมัติ และรายชื่อผู้สมัครสำหรับผู้ดูแล',
    category: 'education',
    sortOrder: 1,
    document: b.build('ระบบลงทะเบียนอบรม', 'ลงทะเบียนเข้าร่วมอบรมออนไลน์', DEFAULT_TOKENS, data),
  };
}

// ---------- 2. School website ----------
function schoolWebsite() {
  const b = new Builder('sc');
  const tokens: DesignTokens = { ...structuredClone(DEFAULT_TOKENS), colors: { ...DEFAULT_TOKENS.colors, primary: '#1d4ed8', secondary: '#1e3a8a', accent: '#facc15' }, fonts: { heading: 'Prompt', body: 'Sarabun' } };
  const nav = () => navbar('โรงเรียนตัวอย่างวิทยา', [['หน้าแรก', '/'], ['เกี่ยวกับโรงเรียน', '/about'], ['ข่าวสาร', '/news'], ['ติดต่อ', '/contact']]);
  const news = (title: string, date: string, body: string) => card([text(date, { color: '$muted', fontSize: '14px' }), heading(title, 'h3'), text(body)]);
  b.page('หน้าแรก', '/', 'โรงเรียนตัวอย่างวิทยา', 'เว็บไซต์ทางการของโรงเรียนตัวอย่างวิทยา', [
    nav(),
    section([
      container([
        heading('ยินดีต้อนรับสู่โรงเรียนตัวอย่างวิทยา', 'h1', { color: '#ffffff' }),
        text('มุ่งพัฒนาผู้เรียนให้มีความรู้คู่คุณธรรม พร้อมก้าวสู่โลกอนาคต', { color: '#dbeafe', fontSize: '19px' }),
        buttons([button('รู้จักโรงเรียน', '/about', 'secondary'), button('ข่าวประชาสัมพันธ์', '/news', 'outline')]),
      ], { maxWidth: '880px' }),
    ], { backgroundColor: '$primary', padding: '96px 24px' }, 'Hero'),
    section([
      container([
        heading('ข่าวประชาสัมพันธ์ล่าสุด', 'h2'),
        columns(3, [
          news('เปิดรับสมัครนักเรียนใหม่', '1 มีนาคม', 'รับสมัครนักเรียนชั้น ม.1 และ ม.4 ปีการศึกษาใหม่'),
          news('กิจกรรมวันวิทยาศาสตร์', '18 สิงหาคม', 'ขอเชิญผู้ปกครองร่วมชมนิทรรศการผลงานนักเรียน'),
          news('ประชุมผู้ปกครอง', '5 มิถุนายน', 'ประชุมผู้ปกครองนักเรียนประจำภาคเรียนที่ 1'),
        ]),
        buttons([button('ดูข่าวทั้งหมด', '/news', 'link')]),
      ]),
    ], {}, 'News'),
    footer('© โรงเรียนตัวอย่างวิทยา'),
  ]);
  b.page('เกี่ยวกับโรงเรียน', '/about', 'เกี่ยวกับโรงเรียน', 'ประวัติ วิสัยทัศน์ และพันธกิจ', [
    nav(),
    section([
      container([
        heading('เกี่ยวกับโรงเรียน', 'h1'),
        heading('วิสัยทัศน์', 'h3'),
        text('ผู้เรียนมีคุณภาพตามมาตรฐานสากล มีทักษะการคิด และดำรงชีวิตอย่างมีความสุข'),
        heading('พันธกิจ', 'h3'),
        el('List', { items: ['จัดการเรียนรู้ที่เน้นผู้เรียนเป็นสำคัญ', 'ส่งเสริมการใช้เทคโนโลยีในการเรียนรู้', 'ปลูกฝังคุณธรรมจริยธรรมและความเป็นพลเมือง'], ordered: false }),
      ], { maxWidth: '880px' }),
    ]),
    footer('© โรงเรียนตัวอย่างวิทยา'),
  ]);
  b.page('ข่าวสาร', '/news', 'ข่าวสารโรงเรียน', 'ข่าวสารและกิจกรรมของโรงเรียน', [
    nav(),
    section([
      container([
        heading('ข่าวสารและกิจกรรม', 'h1'),
        columns(2, [
          news('เปิดรับสมัครนักเรียนใหม่', '1 มีนาคม', 'รับสมัครนักเรียนชั้น ม.1 และ ม.4 ตั้งแต่วันนี้ถึง 31 มีนาคม'),
          news('กิจกรรมวันวิทยาศาสตร์', '18 สิงหาคม', 'นิทรรศการและการแข่งขันทักษะวิทยาศาสตร์'),
          news('ประชุมผู้ปกครอง', '5 มิถุนายน', 'ชี้แจงแนวทางการจัดการเรียนการสอน'),
          news('ทัศนศึกษาประจำปี', '20 พฤศจิกายน', 'นักเรียนทุกระดับชั้นร่วมทัศนศึกษาแหล่งเรียนรู้'),
        ]),
      ]),
    ]),
    footer('© โรงเรียนตัวอย่างวิทยา'),
  ]);
  b.page('ติดต่อ', '/contact', 'ติดต่อโรงเรียน', 'ที่อยู่และช่องทางการติดต่อ', [
    nav(),
    section([
      container([
        heading('ติดต่อโรงเรียน', 'h1'),
        text('99 ถนนการศึกษา ตำบลตัวอย่าง อำเภอเมือง จังหวัดตัวอย่าง 10000'),
        text('โทรศัพท์: 02-000-0000\nอีเมล: info@school.example.com'),
        buttons([button('โทรหาเรา', 'tel:020000000', 'primary'), button('ส่งอีเมล', 'mailto:info@school.example.com', 'outline')]),
      ], { maxWidth: '880px' }),
    ]),
    footer('© โรงเรียนตัวอย่างวิทยา'),
  ]);
  return {
    slug: 'school-website',
    name: 'เว็บไซต์โรงเรียน',
    description: 'เว็บไซต์สถานศึกษา: หน้าแรก เกี่ยวกับโรงเรียน ข่าวสาร และติดต่อ',
    category: 'education',
    sortOrder: 2,
    document: b.build('โรงเรียนตัวอย่างวิทยา', 'เว็บไซต์ทางการของโรงเรียนตัวอย่างวิทยา', tokens),
  };
}

// ---------- 3. Business landing page ----------
function businessLanding() {
  const b = new Builder('bz');
  const tokens: DesignTokens = { ...structuredClone(DEFAULT_TOKENS), colors: { ...DEFAULT_TOKENS.colors, primary: '#0f766e', secondary: '#0b1f33', accent: '#f97316' }, fonts: { heading: 'Kanit', body: 'Noto Sans Thai' }, radius: '16px' };
  const feature = (t: string, d: string) => card([heading(t, 'h3'), text(d)]);
  const price = (name: string, amount: string, items: string[], variant: string) =>
    card([heading(name, 'h3'), heading(amount, 'h2', { color: '$primary' }), el('List', { items, ordered: false }), button('เลือกแพ็กเกจนี้', '#contact', variant)]);
  b.page('หน้าแรก', '/', 'ธุรกิจของคุณ — โซลูชันที่ช่วยให้เติบโต', 'Landing page สำหรับธุรกิจขนาดเล็ก', [
    navbar('ธุรกิจของคุณ', [['บริการ', '#features'], ['ราคา', '#pricing'], ['ติดต่อ', '#contact']]),
    section([
      container([
        heading('ยกระดับธุรกิจของคุณด้วยบริการที่ใช่', 'h1', { color: '#ffffff', textAlign: 'center' }),
        text('เราช่วยธุรกิจขนาดเล็กเพิ่มยอดขาย ลดต้นทุน และเข้าถึงลูกค้าใหม่ได้เร็วขึ้น', { color: '#cbd5e1', fontSize: '19px', textAlign: 'center' }),
        el('Container', {}, { base: { display: 'flex', flexDirection: 'row', justifyContent: 'center', flexWrap: 'wrap', gap: '12px' } }, [button('เริ่มต้นใช้งาน', '#pricing'), button('ปรึกษาฟรี', '#contact', 'outline')]),
      ], { maxWidth: '880px', alignItems: 'center' }),
    ], { backgroundColor: '$secondary', padding: '112px 24px' }, 'Hero'),
    section([
      container([
        heading('บริการของเรา', 'h2', { textAlign: 'center' }),
        columns(3, [
          feature('การตลาดออนไลน์', 'วางกลยุทธ์และจัดการโฆษณาให้ตรงกลุ่มเป้าหมาย'),
          feature('ระบบหลังบ้าน', 'จัดการคำสั่งซื้อ ลูกค้า และสต็อกในที่เดียว'),
          feature('รายงานเชิงลึก', 'ติดตามผลลัพธ์ด้วยแดชบอร์ดที่เข้าใจง่าย'),
        ]),
      ]),
    ], {}, 'Features', 'features'),
    section([
      container([
        heading('แพ็กเกจราคา', 'h2', { textAlign: 'center' }),
        columns(3, [
          price('เริ่มต้น', '฿990/เดือน', ['เว็บไซต์ 1 หน้า', 'อีเมลสนับสนุน'], 'outline'),
          price('ธุรกิจ', '฿2,490/เดือน', ['เว็บไซต์ไม่จำกัดหน้า', 'ระบบหลังบ้าน', 'สนับสนุนทางโทรศัพท์'], 'primary'),
          price('องค์กร', 'ติดต่อฝ่ายขาย', ['ปรับแต่งตามต้องการ', 'ผู้ดูแลเฉพาะ', 'SLA'], 'outline'),
        ]),
      ]),
    ], { backgroundColor: '$surface' }, 'Pricing', 'pricing'),
    section([
      container([
        heading('พร้อมเริ่มต้นแล้วหรือยัง?', 'h2', { textAlign: 'center' }),
        text('ติดต่อทีมงานของเราเพื่อรับคำปรึกษาฟรี', { textAlign: 'center' }),
        el('Container', {}, { base: { display: 'flex', flexDirection: 'row', justifyContent: 'center', flexWrap: 'wrap', gap: '12px' } }, [button('ส่งอีเมล', 'mailto:hello@business.example.com'), button('โทร 02-000-0000', 'tel:020000000', 'outline')]),
      ], { maxWidth: '760px', alignItems: 'center' }),
    ], {}, 'Contact', 'contact'),
    footer('© ธุรกิจของคุณ — สร้างด้วย AI Studio Cloud'),
  ]);
  return {
    slug: 'business-landing',
    name: 'Landing page ธุรกิจ',
    description: 'หน้าเดียวสำหรับธุรกิจ: จุดเด่น บริการ แพ็กเกจราคา และช่องทางติดต่อ',
    category: 'business',
    sortOrder: 3,
    document: b.build('ธุรกิจของคุณ', 'Landing page สำหรับธุรกิจขนาดเล็ก', tokens),
  };
}

const templates = [trainingRegistration(), schoolWebsite(), businessLanding()];
const check = process.argv.includes('--check');
const outDir = fileURLToPath(new URL('./dist/', import.meta.url));
let failed = false;
for (const t of templates) {
  const issues = validateDefinition(t.document);
  if (issues.length) {
    failed = true;
    console.error(`✗ ${t.slug}`, issues);
    continue;
  }
  console.log(`✓ ${t.slug}: ${t.document.pages.length} pages, ${Object.keys(t.document.components).length} components`);
  if (!check) {
    mkdirSync(outDir, { recursive: true });
    writeFileSync(`${outDir}${t.slug}.json`, JSON.stringify(canonicalize(t), null, 2) + '\n');
  }
}
if (failed) process.exit(1);
