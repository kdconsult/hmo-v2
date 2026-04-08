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
        $permissions = Permission::orderBy('name')->pluck('name', 'name')->toArray();

        // Group permissions by model
        $grouped = [];
        foreach ($permissions as $permission) {
            $parts = explode('_', $permission, 2);
            $model = $parts[1] ?? $permission;
            $grouped[$model][] = $permission;
        }

        $sections = [
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(100),
        ];

        foreach ($grouped as $model => $modelPermissions) {
            $sections[] = Section::make(ucwords(str_replace('_', ' ', $model)))
                ->collapsed()
                ->schema([
                    CheckboxList::make('permissions')
                        ->relationship('permissions', 'name')
                        ->options(array_combine($modelPermissions, $modelPermissions))
                        ->columns(3),
                ]);
        }

        return $schema->components($sections);
    }
}
