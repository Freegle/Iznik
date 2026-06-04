import React from 'react';
import { AbsoluteFill, useVideoConfig } from 'remotion';
import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import { COLORS, TRANSITION_FRAMES } from './theme.js';
import { TitleScene } from './scenes/TitleScene.jsx';
import { NarrationScene } from './scenes/NarrationScene.jsx';
import { ScreenshotScene } from './scenes/ScreenshotScene.jsx';
import { CodeScene } from './scenes/CodeScene.jsx';
import { OutroScene } from './scenes/OutroScene.jsx';
import { Brand } from './components/Brand.jsx';
import { ProgressBar } from './components/ProgressBar.jsx';

function SceneSwitch({ scene, meta }) {
  switch (scene.type) {
    case 'title':
      return <TitleScene scene={scene} meta={meta} />;
    case 'narration':
      return <NarrationScene scene={scene} />;
    case 'screenshot':
      return <ScreenshotScene scene={scene} />;
    case 'code':
      return <CodeScene scene={scene} />;
    case 'outro':
      return <OutroScene scene={scene} meta={meta} />;
    default:
      return <AbsoluteFill style={{ background: COLORS.paper }} />;
  }
}

export function Walkthrough({ meta, scenes }) {
  const { fps } = useVideoConfig();
  const sceneFrames = scenes.map((s) => Math.round(s.seconds * fps));

  // Cumulative start frame of each scene, accounting for transition overlaps, for the
  // persistent scene counter.
  const boundaries = [];
  let acc = 0;
  sceneFrames.forEach((f, i) => {
    boundaries.push(acc);
    acc += f - (i < scenes.length - 1 ? TRANSITION_FRAMES : 0);
  });

  return (
    <AbsoluteFill style={{ background: COLORS.paper }}>
      <TransitionSeries>
        {scenes.map((scene, i) => [
          <TransitionSeries.Sequence key={`s${i}`} durationInFrames={sceneFrames[i]}>
            <SceneSwitch scene={scene} meta={meta} />
          </TransitionSeries.Sequence>,
          i < scenes.length - 1 ? (
            <TransitionSeries.Transition
              key={`t${i}`}
              presentation={fade()}
              timing={linearTiming({ durationInFrames: TRANSITION_FRAMES })}
            />
          ) : null,
        ])}
      </TransitionSeries>

      {/* Persistent chrome */}
      <Brand />
      <ProgressBar boundaries={boundaries} sceneCount={scenes.length} />
    </AbsoluteFill>
  );
}
