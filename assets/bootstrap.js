import { startStimulusApp } from '@symfony/stimulus-bridge';
// import '@symfony/autoimport';

import '@symfony/ux-cropperjs';
import 'cropperjs/dist/cropper.css'; 

// Registers Stimulus controllers from controllers.json and in the controllers/ directory
export const app = startStimulusApp(require.context('./controllers', true, /\.(j|t)sx?$/));
