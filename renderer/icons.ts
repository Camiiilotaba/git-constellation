/** Small original outline icons; no browser scripts or external assets. */
export const icon = (name: string, className = ''): string => {
  const paths: Record<string, string> = {
    code: '<path d="m14 10-9 10 9 10m18-20 9 10-9 10M26 6l-8 28"/>',
    branch: '<circle cx="12" cy="9" r="4"/><circle cx="12" cy="33" r="4"/><circle cx="32" cy="13" r="4"/><path d="M12 13v16m0-7h10q10 0 10-5"/>',
    review: '<path d="M8 7h26a4 4 0 0 1 4 4v17a4 4 0 0 1-4 4H20l-10 8v-8H8a4 4 0 0 1-4-4V11a4 4 0 0 1 4-4Z"/><path d="M13 18h16m-16 7h10"/>',
    calendar: '<rect x="5" y="9" width="33" height="30" rx="4"/><path d="M13 4v10M30 4v10M5 20h33m-25 8h7m4 0h7m-18 6h7"/>',
    flame: '<path d="M24 3c4 11-5 12-1 20 3-3 6-5 7-10 15 16 11 27-7 28C7 40 3 30 8 20c1 6 5 7 6 8-1-12 10-14 10-25Z"/>',
  };
  return `<svg class="icon ${className}" viewBox="0 0 44 44" fill="none" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] ?? paths.code}</svg>`;
};
