/**
* Limpia un número de teléfono
*/
private function clean_phone($phone)
{
if (empty($phone)) return null;
// Eliminar espacios, guiones, paréntesis
$clean = preg_replace('/[^0-9+]/', '', $phone);
return !empty($clean) ? $clean : null;
}

/**
* Intenta extraer un email de un texto (muy básico)
*/
private function extract_email_from_text($text)
{
if (empty($text)) return null;
if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $matches)) {
return $matches[0];
}
return null;
}