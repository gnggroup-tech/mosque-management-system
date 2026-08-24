

import Alpine from 'alpinejs';
import registerAccountDirectory from './account-directory';

window.Alpine = Alpine;

registerAccountDirectory(Alpine);
Alpine.start();
