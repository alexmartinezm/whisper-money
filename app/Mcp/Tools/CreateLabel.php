<?php

namespace App\Mcp\Tools;

use App\Enums\LabelColor;
use App\Models\User;
use App\Services\Labels\LabelWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a label. Names are unique within the space.')]
class CreateLabel extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Label name (unique within the space).')->required(),
            'color' => $schema->string()->enum(array_column(LabelColor::cases(), 'value'))->description('Label color.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(LabelWriter::createRules());

        $space = $this->resolveSpace($request, $user);
        $name = $request->string('name')->toString();

        $this->refuseTakenLabelName($space, $name);

        $label = app(LabelWriter::class)->create($user, $space, $name, $request->string('color')->toString());

        return $this->json(['label' => $this->presentLabel($label)]);
    }
}
