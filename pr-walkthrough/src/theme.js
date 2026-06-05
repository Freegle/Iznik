// Visual language for the walkthrough. Freegle-branded: clean light surfaces so the
// app screenshots feel native, with the brand green for chrome and a high-contrast
// amber for callouts so highlights never blend into the green UI.

export const COLORS = {
  // Freegle brand greens (sampled from the app header / buttons)
  green: '#6cae3e',
  greenDark: '#4f8a2a',
  greenDeep: '#3c6f1f',

  // Surfaces
  paper: '#f6f9f1', // light scene background
  paperEdge: '#e7efdd',
  ink: '#21311a', // near-black green-tinted text
  inkSoft: '#52624a',
  white: '#ffffff',

  // Code editor (dark, for contrast & legibility)
  codeBg: '#1b2330',
  codeBar: '#11161f',
  codeInk: '#e6edf3',
  codeLineHi: 'rgba(108, 174, 62, 0.18)',
  codeGutter: '#5b6b7d',

  // Callout / highlight — warm amber, deliberately NOT green
  highlight: '#ff8a1e',
  highlightSoft: 'rgba(255, 138, 30, 0.16)',
  spotlightDim: 'rgba(11, 18, 9, 0.55)',

  // Caption lower-third
  captionBg: 'rgba(20, 28, 16, 0.86)',
  captionInk: '#f4f8ee',
};

export const FONTS = {
  // Filled in at runtime by src/fonts.js (Google Fonts loaded deterministically).
  sans: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
  mono: '"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
};

// Default video format (overridable per-storyboard via meta).
export const VIDEO = {
  width: 1920,
  height: 1080,
  fps: 30,
};

// Cross-fade length between scenes, in frames.
export const TRANSITION_FRAMES = 15;
