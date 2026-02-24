<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "House of Carbon API",
    description: "API documentation for House of Carbon project",
    contact: new OA\Contact(email: "admin@houseofcarbon.com")
)]

#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]

abstract class Controller
{
    //
}
