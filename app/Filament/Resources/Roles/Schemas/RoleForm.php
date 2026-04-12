<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        // Group permissions by model, keying options by ID so loaded state matches
        $grouped = [];
        Permission::orderBy('name')->get()->each(function ($permission) use (&$grouped) {
            $parts = explode('_', $permission->name, 2);
            $model = $parts[1] ?? $permission->name;
            $grouped[$model][$permission->id] = $permission->name;
        });

        $sections = [
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(100),
        ];

        foreach ($grouped as $model => $options) {
            $sections[] = Section::make(ucwords(str_replace('_', ' ', $model)))
                ->collapsed()
                ->schema([
                    CheckboxList::make('permissions')
                        ->relationship('permissions', 'name')
                        ->options($options)
                        ->columns(3),
                ]);
        }

        return $schema->components($sections);
    }
}
