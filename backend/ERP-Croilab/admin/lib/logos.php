<?php
/* Logos de herramientas de terceros (SVG en línea, autocontenidos, sin depender de
   internet ni romper la seguridad del ERP). Úsalo en cualquier sitio donde aparezca
   una app conocida:  echo svc_logo('gcal', 26);  */
if (!function_exists('svc_logo')) {
function svc_logo($k, $s = 26){
  switch($k){
    case 'gcal': // Google Calendar
      return '<svg viewBox="0 0 48 48" width="'.$s.'" height="'.$s.'" aria-label="Google Calendar"><rect x="9" y="9" width="30" height="30" rx="5" fill="#fff" stroke="#e6e6e6"/><path d="M17 9h14v6H17z" fill="#4285F4"/><path d="M33 15h6v14h-6z" fill="#FBBC04"/><path d="M17 33h14v6H17z" fill="#34A853"/><path d="M9 15h6v14H9z" fill="#EA4335"/><rect x="15" y="15" width="18" height="18" fill="#fff"/><text x="24" y="29" font-size="13" font-weight="700" fill="#4285F4" text-anchor="middle" font-family="Arial,Helvetica,sans-serif">31</text></svg>';
    case 'google': // «G» de Google (4 colores)
      return '<svg viewBox="0 0 48 48" width="'.$s.'" height="'.$s.'" aria-label="Google"><path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z"/><path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z"/><path fill="#FBBC05" d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18v-5.7H4.34C2.85 17.09 2 20.45 2 24s.85 6.91 2.34 9.88l7.35-5.7z"/><path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"/></svg>';
    case 'meet': // Google Meet (logo oficial). No es cuadrado: ancho proporcional a la altura $s.
      $w = round($s * 87.5 / 72);
      return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 87.5 72" width="'.$w.'" height="'.$s.'" aria-label="Google Meet">'
        .'<path fill="#00832d" d="M49.5 36l8.53 9.75 11.47 7.33 2-17.02-2-16.64-11.69 6.44z"/>'
        .'<path fill="#0066da" d="M0 51.5V66c0 3.315 2.685 6 6 6h14.5l3-10.96-3-9.54-9.95-3z"/>'
        .'<path fill="#e94235" d="M20.5 0L0 20.5l10.55 3 9.95-3 2.95-9.41z"/>'
        .'<path fill="#2684fc" d="M0 20.5h20.5v31H0z"/>'
        .'<path fill="#00ac47" d="M82.6 8.68L69.5 19.42v33.66l13.16 10.79c1.97 1.54 4.85.135 4.85-2.37V11c0-2.535-2.945-3.925-4.91-2.32z"/>'
        .'<path fill="#ffba00" d="M49.5 36v15.5H20.5V72h43c3.315 0 6-2.685 6-6V53.08z"/>'
        .'<path fill="#00ac47" d="M63.5 0h-43v20.5h29V36l20-16.58V6c0-3.315-2.685-6-6-6z"/>'
        .'</svg>';
    case 'gemini': // Gemini — destello oficial (estrella de 4 puntas cóncava) con su degradado
      $u = 'gm'.$s.substr(md5((string)$s), 0, 4);
      return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="'.$s.'" height="'.$s.'" aria-label="Gemini">'
        .'<defs><linearGradient id="'.$u.'" x1="2" y1="20" x2="22" y2="4" gradientUnits="userSpaceOnUse">'
        .'<stop stop-color="#1BA1E3"/><stop offset=".3" stop-color="#5489D6"/><stop offset=".55" stop-color="#9B72CB"/><stop offset=".82" stop-color="#D96570"/><stop offset="1" stop-color="#F49C46"/>'
        .'</linearGradient></defs>'
        .'<path fill="url(#'.$u.')" d="M12 24A14.304 14.304 0 0 0 0 12 14.304 14.304 0 0 0 12 0a14.305 14.305 0 0 0 12 12 14.305 14.305 0 0 0-12 12Z"/>'
        .'</svg>';
    case 'claude': // Claude / Anthropic (estrella clay)
      return '<svg viewBox="-50 -50 100 100" width="'.$s.'" height="'.$s.'" aria-label="Claude"><g stroke="#D97757" stroke-width="8" stroke-linecap="round">'
        .'<line x1="0" y1="0" x2="0" y2="-42"/><line x1="0" y1="0" x2="21" y2="-36"/><line x1="0" y1="0" x2="36" y2="-21"/><line x1="0" y1="0" x2="42" y2="0"/>'
        .'<line x1="0" y1="0" x2="36" y2="21"/><line x1="0" y1="0" x2="21" y2="36"/><line x1="0" y1="0" x2="0" y2="42"/><line x1="0" y1="0" x2="-21" y2="36"/>'
        .'<line x1="0" y1="0" x2="-36" y2="21"/><line x1="0" y1="0" x2="-42" y2="0"/><line x1="0" y1="0" x2="-36" y2="-21"/><line x1="0" y1="0" x2="-21" y2="-36"/></g></svg>';
    case 'n8n': // n8n (nodos)
      return '<svg viewBox="0 0 68 40" width="'.$s.'" height="'.round($s*40/68).'" aria-label="n8n"><g fill="none" stroke="#EA4B71" stroke-width="3"><path d="M12 20h14"/><path d="M40 12h10"/><path d="M40 28h10"/><path d="M26 20l14-8"/><path d="M26 20l14 8"/></g><g fill="#EA4B71"><circle cx="10" cy="20" r="6"/><circle cx="28" cy="20" r="5"/><circle cx="54" cy="12" r="6"/><circle cx="54" cy="28" r="6"/></g></svg>';
  }
  return '';
}
}
