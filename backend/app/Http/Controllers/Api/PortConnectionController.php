<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevicePort;
use App\Models\PortConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PortConnectionController extends Controller
{
    public function index(): JsonResponse
    {
        $connections = PortConnection::with([
            'sourceDevice',
            'sourcePort',
            'targetDevice',
            'targetPort',
        ])
            ->ownedBy(Auth::id())
            ->latest()
            ->get();

        return response()->json($connections);
    }

    public function show(PortConnection $portConnection): JsonResponse
    {
        return response()->json(
            $portConnection->load([
                'sourceDevice',
                'sourcePort',
                'targetDevice',
                'targetPort',
            ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_device_id' => ['required', 'integer', 'exists:devices,id'],
            'source_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'target_device_id' => ['required', 'integer', 'exists:devices,id'],
            'target_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'connection_type' => ['nullable', 'string', 'max:50'],
            'cable_type' => ['nullable', 'string', 'max:100'],
            'cable_length' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->validatePortOwnership($data);
        $this->validatePortsAvailable($data);

        $connection = PortConnection::create([
            ...$data,
            'connection_type' => $data['connection_type'] ?? 'direct',
            'status' => $data['status'] ?? 'active',
        ]);

        $this->markPortsAsConnected($data);

        return response()->json(
            $connection->load([
                'sourceDevice',
                'sourcePort',
                'targetDevice',
                'targetPort',
            ]),
            201
        );
    }

    public function update(
        Request $request,
        PortConnection $portConnection
    ): JsonResponse {
        $data = $request->validate([
            'source_device_id' => ['required', 'integer', 'exists:devices,id'],
            'source_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'target_device_id' => ['required', 'integer', 'exists:devices,id'],
            'target_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'connection_type' => ['nullable', 'string', 'max:50'],
            'cable_type' => ['nullable', 'string', 'max:100'],
            'cable_length' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->validatePortOwnership($data);
        $this->validatePortsAvailable($data, $portConnection->id);

        $oldData = $portConnection->toArray();

        $portConnection->update([
            ...$data,
            'connection_type' => $data['connection_type'] ?? 'direct',
            'status' => $data['status'] ?? 'active',
        ]);

        $this->restorePortStatus($oldData);
        $this->markPortsAsConnected($data);

        return response()->json(
            $portConnection->fresh()->load([
                'sourceDevice',
                'sourcePort',
                'targetDevice',
                'targetPort',
            ])
        );
    }

    public function destroy(PortConnection $portConnection): JsonResponse
    {
        $data = $portConnection->toArray();

        $portConnection->delete();

        $this->restorePortStatus($data);

        return response()->json([
            'message' => 'Verbindung wurde gelöscht.',
        ]);
    }

    private function validatePortOwnership(array $data): void
    {
        // Nur Ports aus dem eigenen Workspace. Fremde gelten als nicht
        // vorhanden; ueber die Geraete-Pruefung unten sind damit auch
        // beide Geraete die eigenen.
        $sourcePort = DevicePort::query()->ownedBy(Auth::id())->find($data['source_port_id']);
        $targetPort = DevicePort::query()->ownedBy(Auth::id())->find($data['target_port_id']);

        if (! $sourcePort || ! $targetPort) {
            throw ValidationException::withMessages([
                $sourcePort ? 'target_port_id' : 'source_port_id' => 'Der ausgewählte Port existiert nicht.',
            ]);
        }

        if ((int) $sourcePort->device_id !== (int) $data['source_device_id']) {
            throw ValidationException::withMessages([
                'source_port_id' => 'Der Quellport gehört nicht zum angegebenen Quellgerät.',
            ]);
        }

        if ((int) $targetPort->device_id !== (int) $data['target_device_id']) {
            throw ValidationException::withMessages([
                'target_port_id' => 'Der Zielport gehört nicht zum angegebenen Zielgerät.',
            ]);
        }

        if (
            (int) $data['source_device_id'] === (int) $data['target_device_id'] &&
            (int) $data['source_port_id'] === (int) $data['target_port_id']
        ) {
            throw ValidationException::withMessages([
                'target_port_id' => 'Ein Port kann nicht mit sich selbst verbunden werden.',
            ]);
        }
    }

    private function validatePortsAvailable(
        array $data,
        ?int $ignoreConnectionId = null
    ): void {
        $query = PortConnection::query()
            ->where(function ($query) use ($data) {
                $query
                    ->where(function ($query) use ($data) {
                        $query
                            ->where('source_port_id', $data['source_port_id'])
                            ->orWhere('target_port_id', $data['source_port_id']);
                    })
                    ->orWhere(function ($query) use ($data) {
                        $query
                            ->where('source_port_id', $data['target_port_id'])
                            ->orWhere('target_port_id', $data['target_port_id']);
                    });
            });

        if ($ignoreConnectionId !== null) {
            $query->where('id', '!=', $ignoreConnectionId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'source_port_id' => 'Mindestens einer der ausgewählten Ports ist bereits verbunden.',
            ]);
        }
    }

    private function markPortsAsConnected(array $data): void
    {
        DevicePort::whereIn('id', [
            $data['source_port_id'],
            $data['target_port_id'],
        ])->update([
            'status' => 'connected',
        ]);
    }

    private function restorePortStatus(array $data): void
    {
        DevicePort::whereIn('id', [
            $data['source_port_id'],
            $data['target_port_id'],
        ])->update([
            'status' => 'free',
        ]);
    }
}
