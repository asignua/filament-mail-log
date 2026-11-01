<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Orders;

use Asignua\FilamentMailLog\Filament\RelationManagers\MailLogsRelationManager;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Filament\Resources\Orders\Pages\ViewOrder;
use Workbench\App\Models\Order;

/**
 * A host resource for the demo: the "Emails" tab on an order is the plugin's relation manager.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'number';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('number'),
            TextEntry::make('customer_name'),
            TextEntry::make('customer_email'),
            TextEntry::make('status')->badge(),
        ])->columns(4);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->searchable(),
            TextColumn::make('customer_name'),
            TextColumn::make('total_cents')->label('Total')->money('EUR', 100)->alignEnd(),
            TextColumn::make('status')->badge(),
        ])->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [MailLogsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
