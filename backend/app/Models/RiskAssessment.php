<?php

namespace App\Models;

class RiskAssessment extends Document
{
    protected $table = 'evaluaciones_riesgo';

    protected $hidden = ['fingerprint'];
}
