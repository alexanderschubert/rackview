<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\PortConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class DevicePortController extends Controller {
 /**
  * Ports eines Geraets, natuerlich sortiert ("Port 2" vor "Port 10"),
  * jeweils mit der Verbindung aus port_connections, falls vorhanden.
  */
 public function index(Device $device)
 {
     $ports = $device->ports()->get()
         ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
         ->values();

     $ids = $ports->pluck('id');

     $verbindungen = PortConnection::query()
         ->with([
             'sourceDevice:id,name',
             'sourcePort:id,name',
             'targetDevice:id,name',
             'targetPort:id,name',
         ])
         ->where(fn ($q) => $q->whereIn('source_port_id', $ids)->orWhereIn('target_port_id', $ids))
         ->get();

     return $ports->map(function (DevicePort $port) use ($verbindungen) {
         $verbindung = $verbindungen->first(
             fn (PortConnection $c) => (int) $c->source_port_id === (int) $port->id
                 || (int) $c->target_port_id === (int) $port->id
         );

         $port->setAttribute('connection', $verbindung ? $this->describeConnection($verbindung, $port) : null);

         return $port;
     });
 }

 /**
  * Die Verbindung aus Sicht dieses Ports: Die Gegenstelle ist die
  * jeweils andere Seite, egal ob der Port Quelle oder Ziel ist.
  */
 private function describeConnection(PortConnection $verbindung, DevicePort $port): array
 {
     $istQuelle = (int) $verbindung->source_port_id === (int) $port->id;

     $gegengeraet = $istQuelle ? $verbindung->targetDevice : $verbindung->sourceDevice;
     $gegenport = $istQuelle ? $verbindung->targetPort : $verbindung->sourcePort;

     return [
         'id' => $verbindung->id,
         'status' => $verbindung->status,
         'connection_type' => $verbindung->connection_type,
         'cable_type' => $verbindung->cable_type,
         'cable_length' => $verbindung->cable_length,
         'peer_device' => $gegengeraet?->only(['id', 'name']),
         'peer_port' => $gegenport?->only(['id', 'name']),
     ];
 }
 public function store(Request $request, Device $device){ $data=$this->data($request); $data['device_id']=$device->id; return response()->json(DevicePort::create($data),201); }
 public function update(Request $request, Device $device, DevicePort $port){ abort_unless($port->device_id===$device->id,404); $port->update($this->data($request,$port)); return $port->fresh(); }
 public function destroy(Device $device, DevicePort $port){ abort_unless($port->device_id===$device->id,404); $port->delete(); return response()->json(['message'=>'Port gelöscht.']); }
 private function data(Request $r, ?DevicePort $port=null): array { return $r->validate(['name'=>['required','string','max:100',Rule::unique('device_ports')->where(fn($q)=>$q->where('device_id',$port?->device_id ?? request()->route('device')->id))->ignore($port?->id)],'port_type'=>['required',Rule::in(['ethernet','sfp','sfp+','sfp28','qsfp','fiber','power','console','usb','other'])],'speed'=>['nullable','string','max:20'],'poe'=>['nullable',Rule::in(['passive','poe','poe+','poe++'])],'status'=>['required',Rule::in(['free','occupied','faulty','disabled'])],'vlan'=>['nullable','string','max:100'],'notes'=>['nullable','string']]); }
}
