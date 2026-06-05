import React from 'react';
import { Composition } from 'remotion';
import { Walkthrough } from './Walkthrough.jsx';
import { VIDEO, TRANSITION_FRAMES } from './theme.js';
import { totalDurationInFrames } from './storyboard-schema.mjs';
import storyboard from '../prs/pr-618/storyboard.json';

// The storyboard is supplied as input props at render time (`remotion render --props`).
// The bundled example is the default so `remotion studio` shows the 618 walkthrough.
export const RemotionRoot = () => {
  return (
    <Composition
      id="Walkthrough"
      component={Walkthrough}
      defaultProps={storyboard}
      durationInFrames={300}
      fps={VIDEO.fps}
      width={VIDEO.width}
      height={VIDEO.height}
      calculateMetadata={({ props }) => {
        const fps = props.meta?.fps || VIDEO.fps;
        const { total } = totalDurationInFrames(props, fps, TRANSITION_FRAMES);
        return {
          durationInFrames: total,
          fps,
          width: props.meta?.width || VIDEO.width,
          height: props.meta?.height || VIDEO.height,
        };
      }}
    />
  );
};
