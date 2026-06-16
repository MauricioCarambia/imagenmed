<?php
// ============================================================
//  ImagenMed · Configuración de envío de email (SMTP)
// ============================================================
// Completá estos datos con tu cuenta de Gmail (o el SMTP que uses).
// Para Gmail: necesitás generar una "Contraseña de aplicación" en
// https://myaccount.google.com/apppasswords (requiere verificación
// en 2 pasos activada). NO uses tu contraseña normal de Gmail.

return [
    'enabled'    => false, // poner en true cuando completes los datos

    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',     // 'tls' o 'ssl'

    'username'   => '', // tu_email@gmail.com
    'password'   => '', // contraseña de aplicación de 16 caracteres

    'from_email' => '', // normalmente igual a 'username'
    'from_name'  => 'ImagenMed',
];
