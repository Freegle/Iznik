// Deterministic web-font loading for Remotion. Loaded once; family names exported
// for use across scenes. Falls back to system fonts in theme.js if a load fails.
import { loadFont as loadInter } from '@remotion/google-fonts/Inter';
import { loadFont as loadMono } from '@remotion/google-fonts/JetBrainsMono';

const inter = loadInter('normal', { weights: ['400', '600', '700', '800'], subsets: ['latin'] });
const mono = loadMono('normal', { weights: ['400', '600'], subsets: ['latin'] });

export const SANS = `${inter.fontFamily}, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
export const MONO = `${mono.fontFamily}, ui-monospace, SFMono-Regular, Menlo, monospace`;
