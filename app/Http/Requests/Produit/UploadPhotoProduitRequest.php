<?php

declare(strict_types=1);

namespace App\Http\Requests\Produit;

use App\Models\Produit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadPhotoProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Produit $produit */
        $produit = $this->route('produit');

        return $this->user()->can('update', $produit);
    }

    /**
     * La photo alimente le menu public, consulté au téléphone : le front la
     * redimensionne et la réencode avant l'envoi (~80–200 ko). La limite ci-dessous
     * n'est donc pas la cible mais un garde-fou contre un appel direct à l'API.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.image' => 'Le fichier doit être une image.',
            'photo.mimes' => 'Formats acceptés : JPEG, PNG ou WebP.',
            'photo.max' => 'L\'image ne doit pas dépasser 2 Mo.',
        ];
    }
}
