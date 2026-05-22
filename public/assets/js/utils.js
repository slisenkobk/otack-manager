// Hashes a tag name to one of 10 design-system tag colors (matches DESIGN.md §1).
const TAG_HUES = [
  { color: 'var(--brand)',   bg: 'var(--brand-3)' },
  { color: 'var(--green)',   bg: 'var(--green-tint)' },
  { color: 'var(--red)',     bg: 'var(--red-tint)' },
  { color: 'var(--blue)',    bg: 'var(--blue-tint)' },
  { color: 'var(--yellow)',  bg: 'var(--yellow-tint)' },
  { color: 'var(--teal)',    bg: 'var(--teal-tint)' },
  { color: 'var(--purple)',  bg: 'var(--purple-tint)' },
  { color: 'var(--magenta)', bg: 'var(--magenta-tint)' },
  { color: 'var(--olive)',   bg: 'var(--olive-tint)' },
  { color: 'var(--indigo)',  bg: 'var(--indigo-tint)' },
];

export function tagColor(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
  return TAG_HUES[Math.abs(h) % TAG_HUES.length];
}
