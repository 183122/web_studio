import type { AppDefinition } from '../src/types.ts';

/** Deterministic document (fixed ids) used by tests and parity fixtures. */
export function sampleDoc(): AppDefinition {
  return {
    schemaVersion: 2,
    projectId: '01JTESTPROJECT0000000000000',
    name: 'ทดสอบ',
    pages: [
      { id: 'pg_home', name: 'หน้าแรก', path: '/', rootId: 'p_home', seo: { title: 'หน้าแรก', description: '', noindex: false } },
      { id: 'pg_about', name: 'เกี่ยวกับ', path: '/about', rootId: 'p_about', seo: { title: 'เกี่ยวกับเรา', description: 'ข้อมูล', noindex: false } },
    ],
    routes: [],
    components: {
      p_home: { id: 'p_home', type: 'Page', props: {}, styles: {}, children: ['c_section'] },
      c_section: { id: 'c_section', type: 'Section', props: { tag: 'section', anchorId: '', backgroundImage: '' }, styles: { base: { padding: '64px 24px' } }, children: ['c_heading', 'c_text', 'c_box'] },
      c_heading: { id: 'c_heading', type: 'Heading', props: { text: 'สวัสดี', level: 'h1' }, styles: { base: { fontSize: '40px' }, mobile: { fontSize: '28px' } }, children: [] },
      c_text: { id: 'c_text', type: 'Text', props: { text: 'ยินดีต้อนรับ\nบรรทัดสอง' }, styles: {}, children: [] },
      c_box: { id: 'c_box', type: 'Container', props: {}, styles: { base: { display: 'flex', gap: '16px' } }, children: ['c_btn'] },
      c_btn: { id: 'c_btn', type: 'Button', props: { label: 'สมัคร', href: '/register', variant: 'primary', newTab: false }, styles: {}, children: [] },
      p_about: { id: 'p_about', type: 'Page', props: {}, styles: {}, children: ['c_img', 'c_form', 'c_table'] },
      c_form: { id: 'c_form', type: 'Form', props: { modelId: 'm_reg', submitLabel: 'สมัคร', successMessage: 'ขอบคุณ' }, styles: {}, children: ['c_ff_name', 'c_ff_email'] },
      c_ff_name: { id: 'c_ff_name', type: 'FormField', props: { fieldId: 'f_name', label: '', placeholder: 'ชื่อ', helpText: '' }, styles: {}, children: [] },
      c_ff_email: { id: 'c_ff_email', type: 'FormField', props: { fieldId: 'f_email', label: 'อีเมล', placeholder: '', helpText: '' }, styles: {}, children: [] },
      c_table: { id: 'c_table', type: 'DataTable', props: { modelId: 'm_reg', fieldIds: ['f_name', 'f_status'], pageSize: '10', searchable: true, emptyMessage: 'ว่าง' }, styles: {}, children: [] },
      c_img: { id: 'c_img', type: 'Image', props: { src: 'asset:01HZZZZZZZZZZZZZZZZZZZZZZZ', alt: 'ทีมงาน' }, styles: { base: { width: '100%' } }, children: [] },
    },
    designTokens: {
      colors: { primary: '#0f766e', secondary: '#1e3a5f', accent: '#f59e0b', background: '#ffffff', surface: '#f8fafc', text: '#0f172a', muted: '#64748b' },
      fonts: { heading: 'Kanit', body: 'Sarabun' },
      radius: '12px',
    },
    dataModels: [
      {
        id: 'm_reg',
        name: 'registrations',
        label: 'ผู้สมัคร',
        displayFieldId: 'f_name',
        fields: [
          { id: 'f_name', name: 'full_name', label: 'ชื่อ–นามสกุล', type: 'text', required: true, unique: false, maxLength: 120 },
          { id: 'f_email', name: 'email', label: 'อีเมล', type: 'email', required: true, unique: true },
          { id: 'f_seats', name: 'seats', label: 'จำนวนที่นั่ง', type: 'integer', required: false, unique: false, min: 1, max: 10 },
          { id: 'f_status', name: 'status', label: 'สถานะ', type: 'select', required: false, unique: false, options: ['pending', 'confirmed', 'cancelled'] },
        ],
      },
    ],
    permissions: [{ modelId: 'm_reg', rules: { public: ['create'], member: ['read'], admin: ['create', 'read', 'update', 'delete'] } }],
    actions: [],
    workflows: [
      {
        id: 'w_welcome',
        name: 'ยืนยันการสมัคร',
        enabled: true,
        trigger: { event: 'record.created', modelId: 'm_reg' },
        conditions: [{ fieldId: 'f_email', op: 'not_empty' }],
        actions: [
          { type: 'record.setField', fieldId: 'f_status', value: 'pending' },
          { type: 'email.send', to: 'field:f_email', subject: 'ได้รับใบสมัครของ {{full_name}}', body: 'สวัสดี {{ full_name }} เราได้รับข้อมูลแล้ว' },
        ],
      },
    ],
    integrations: [],
    assets: ['01HZZZZZZZZZZZZZZZZZZZZZZZ'],
    seo: { siteName: 'ทดสอบ', description: '', lang: 'th' },
    settings: { homePageId: 'pg_home', notFoundPageId: null },
  };
}
