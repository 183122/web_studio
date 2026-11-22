import {
  Columns3, CreditCard, File, Heading, Image, List, Menu, Minus, MousePointerClick, MoveVertical, Rows3, Square, Type, Video, type LucideIcon,
} from 'lucide-react';

const MAP: Record<string, LucideIcon> = {
  file: File, rows: Rows3, square: Square, columns: Columns3, 'credit-card': CreditCard, heading: Heading, type: Type,
  'mouse-pointer-click': MousePointerClick, image: Image, video: Video, menu: Menu, list: List, minus: Minus, 'move-vertical': MoveVertical,
};

export function iconFor(name: string): LucideIcon {
  return MAP[name] ?? Square;
}
