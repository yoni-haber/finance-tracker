import './charts';
import { installUiFeedback } from './ui-feedback';

document.addEventListener('livewire:init', () => installUiFeedback({
    document,
    Livewire: window.Livewire,
    MutationObserver,
    requestAnimationFrame,
}));
