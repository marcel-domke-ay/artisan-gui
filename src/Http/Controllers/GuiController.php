<?php

namespace Infureal\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class GuiController extends Controller
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    public function index()
    {

        if (request()->wantsJson()) {
            return $this->prepareToJson(config('artisan-gui.commands', []));
        }

        return view('gui::index');
    }

    public function run($command)
    {
        $command = $this->findCommandOrFail($command);
        $permissions = config('artisan-gui.permissions', []);

        if (in_array($command->getName(), array_keys($permissions)) && !Gate::check($permissions[$command->getName()])) {
            abort(403);
        }

        $rules = $this->buildRules($command);
        $data = request()->validate($rules);

        $data = array_filter($data);
        $options = array_merge(
            $command->getDefinition()->getOptions(),
            [
                'env' => new InputOption('env', null, 2, 'The environment the command should run under'),
            ]
        );
        $optionKeys = array_keys($options);

        $params = [];

        foreach ($data as $key => $value) {

            if (in_array($key, $optionKeys)) {
                $key = "--{$key}";
            }

            $params[$key] = $value;
        }
        $params['--no-ansi'] = '';
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);

        try {
            $status = Artisan::call($command->getName(), $params, $output);
            $output = $output->fetch();
        } catch (\Exception $exception) {
            $status = $exception->getCode() ?? 500;
            $output = $exception->getMessage();
        }

        $res = [
            'status' => $status,
            'output' => $output,
            'command' => $command->getName(),
        ];

        if (request()->wantsJson()) {
            return $res;
        }

        return back()
            ->with($res);
    }

    protected function prepareToJson(array $commands): array
    {
        $commands = $this->renameKeys($commands);
        $defined = Artisan::all();

        $permissions = config('artisan-gui.permissions', []);

        foreach ($commands as $gKey => $group) {
            foreach ($group as $cKey => $command) {

                if (($permission = $permissions[$command] ?? null) && !Gate::check($permission)) {
                    unset($commands[$gKey][$cKey]);

                    continue;
                }

                $commands[$gKey][$cKey] = $this->commandToArray($defined[$command] ?? $command);
            }

            if (empty($commands[$gKey])) {
                unset($commands[$gKey]);

                continue;
            }

            $commands[$gKey] = array_values($commands[$gKey]);
        }

        return $commands;
    }

    protected function commandToArray($command): ?array
    {

        if ($command === null) {
            return null;
        }

        if (!$command instanceof Command) {
            return [
                'name' => $command,
                'error' => 'Not found',
            ];
        }

        return [
            'name' => $command->getName(),
            'description' => $command->getDescription(),
            'synopsis' => $command->getSynopsis(),
            'arguments' => $this->argumentsToArray($command),
            'options' => $this->optionsToArray($command),
        ];

    }

    protected function optionsToArray(Command $command): ?array
    {
        $definition = $command->getDefinition();
        $definition->setOptions(array_merge($definition->getOptions(), [
            new InputOption('env', null, 2, 'The environment the command should run under'),
        ]));
        $options = array_map(function (InputOption $option) {
            return [
                'title' => Str::of($option->getName())->snake()->replace('_', ' ')->title()->__toString(),
                'name' => $option->getName(),
                'description' => $option->getDescription(),
                'shortcut' => $option->getShortcut(),
                'required' => $option->isValueRequired(),
                'array' => $option->isArray(),
                'accept_value' => $option->acceptValue(),
                'default' => empty($default = $option->getDefault()) ? null : $default,
            ];
        }, $definition->getOptions());

        return empty($options) ? null : $options;
    }

    protected function argumentsToArray(Command $command): ?array
    {
        $definition = $command->getDefinition();
        $arguments = array_map(function (InputArgument $argument) {
            return [
                'title' => Str::of($argument->getName())->snake()->replace('_', ' ')->title()->__toString(),
                'name' => $argument->getName(),
                'description' => $argument->getDescription(),
                'default' => empty($default = $argument->getDefault()) ? null : $default,
                'required' => $argument->isRequired(),
                'array' => $argument->isArray(),
            ];
        }, $definition->getArguments());

        return empty($arguments) ? null : $arguments;
    }

    protected function renameKeys(array $array): array
    {
        $keys = array_map(function ($key) {
            return Str::title($key);
        }, array_keys($array));

        return array_combine($keys, array_values($array));
    }

    protected function findCommandOrFail(string $name): Command
    {
        $commands = Artisan::all();

        if (!in_array($name, array_keys($commands))) {
            abort(404);
        }

        return $commands[$name];
    }

    protected function buildRules(Command $command)
    {
        $rules = [];

        foreach ($command->getDefinition()->getArguments() as $argument) {
            $rules[$argument->getName()] = [
                $argument->isRequired() ? 'required' : 'nullable',
            ];
        }

        $data = array_merge($command->getDefinition()->getOptions(), [
            'env' => new InputOption('env', null, 2, 'The environment the command should run under'),
        ]);

        foreach ($data as $option) {
            $rules[$option->getName()] = [
                $option->isValueRequired() ? 'required' : 'nullable',
                $option->acceptValue() ? ($option->isArray() ? 'array' : 'string') : 'bool',
            ];
        }

        return $rules;
    }
}
