import React from 'react';
import ReactDOM from 'react-dom/client';
import { AssetCalendar } from '../calendar';
import type { CalendarInput, CalendarOutput } from '../calendar';
import '../calendar/assetCalendar.css';

declare global {
  interface Window {
    assetCalendarInput?: CalendarInput;
    assetCalendarOnConfirm?: (output: CalendarOutput) => void;
    assetCalendarMount?: (rootEl: HTMLElement, input: CalendarInput, onConfirm?: (output: CalendarOutput) => void) => void;
  }
}

function mountCalendar(
  rootEl: HTMLElement,
  input: CalendarInput,
  onConfirm?: (output: CalendarOutput) => void,
) {
  // Unmount any previous React root on this element
  // ReactDOM v18: createRoot replaces existing content
  rootEl.innerHTML = '';
  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <AssetCalendar
        input={input}
        onConfirm={(output) => {
          if (typeof onConfirm === 'function') {
            onConfirm(output);
            return;
          }
          if (typeof window.assetCalendarOnConfirm === 'function') {
            window.assetCalendarOnConfirm(output);
            return;
          }
          console.log('Asset calendar output:', output);
        }}
      />
    </React.StrictMode>,
  );
}

// Expose a global mount function for dynamic use
window.assetCalendarMount = mountCalendar;

// Auto-mount to #asset-calendar-root on page load if input is present
const rootEl = document.getElementById('asset-calendar-root');
if (rootEl && window.assetCalendarInput) {
  mountCalendar(rootEl, window.assetCalendarInput, window.assetCalendarOnConfirm);
}
