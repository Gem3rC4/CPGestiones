<?php

namespace App\Models;

class IntegrationSetting extends Document
{
    protected $table = 'configuracion_integraciones';

    // The shared secret is encrypted at rest and never returned by the API.
    protected $hidden = ['secreto'];
}
