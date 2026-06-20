package _plugin

import (
	"bytes"
	"compress/gzip"
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math"
	"math/big"
	mrand "math/rand"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/labstack/echo/v4"
	"gorm.io/gorm"

	_function "github.com/BANKA2017/tbsign_go/functions"
	"github.com/BANKA2017/tbsign_go/model"
)

func init() {
	PluginList.Register(WeltolkAutoReplyPlugin)
}

type WeltolkAutoReplyPluginType struct {
	PluginInfo
}

var WeltolkAutoReplyPlugin = _function.VPtr(WeltolkAutoReplyPluginType{
	PluginInfo{
		Name:              "weltolk_autoreply",
		PluginNameCN:      "自动回帖",
		PluginNameCNShort: "自动回帖",
		PluginNameFE:      "weltolk_autoreply",
		Version:           "1.0",
		Options: map[string]string{
			"weltolk_autoreply_limit":        "5",
			"weltolk_autoreply_id":           "0",
			"weltolk_autoreply_action_limit": "50",
		},
		SettingOptions: map[string]PluginSettingOption{
			"weltolk_autoreply_limit": {
				OptionName:   "weltolk_autoreply_limit",
				OptionNameCN: "默认任务数量上限",
				Validate: &_function.OptionRule{
					Min: _function.VPtr(int64(1)),
				},
			},
			"weltolk_autoreply_action_limit": {
				OptionName:   "weltolk_autoreply_action_limit",
				OptionNameCN: "每分钟最大执行数",
				Validate: &_function.OptionRule{
					Min: _function.VPtr(int64(0)),
				},
			},
		},
		Endpoints: []PluginEndpointStruct{
			{Method: http.MethodGet, Path: "switch", Function: PluginWeltolkAutoReplyGetSwitch},
			{Method: http.MethodPost, Path: "switch", Function: PluginWeltolkAutoReplySwitch},
			{Method: http.MethodGet, Path: "list", Function: PluginWeltolkAutoReplyList},
			{Method: http.MethodPatch, Path: "list", Function: PluginWeltolkAutoReplyListAdd},
			{Method: http.MethodDelete, Path: "list/:id", Function: PluginWeltolkAutoReplyListDelete},
			{Method: http.MethodPost, Path: "list/empty", Function: PluginWeltolkAutoReplyListEmpty},
			{Method: http.MethodPost, Path: "test", Function: PluginWeltolkAutoReplyTest},
			{Method: http.MethodGet, Path: "settings", Function: PluginWeltolkAutoReplySettings},
			{Method: http.MethodPut, Path: "settings", Function: PluginWeltolkAutoReplySettingsUpdate},
		},
	},
})

const weltolkAutoreplyOpenKey = "weltolk_autoreply_open"
const weltolkAutoreplyLimitKey = "weltolk_autoreply_limit"
const weltolkAutoreplyHighWaterKey = "weltolk_autoreply_high_water"

// helpers

func weltolkAutoreplyGetUserLimit(uid string) int {
	personal := _function.GetUserOption(weltolkAutoreplyLimitKey, uid)
	if p, err := strconv.Atoi(personal); err == nil && p > 0 {
		return p
	}
	global := _function.GetOption(weltolkAutoreplyLimitKey)
	if g, err := strconv.Atoi(global); err == nil && g > 0 {
		return g
	}
	return 5
}

func weltolkAutoreplyAppendLog(taskID int32, entry string) {
	var task model.TcWeltolkAutoreplyTasks
	if err := _function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Select("log").Take(&task).Error; err != nil {
		return
	}
	task.Log += entry
	_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Update("log", task.Log)
}

func weltolkAutoreplyNowString(now int64) string {
	return time.Unix(now, 0).Format("2006-01-02 15:04:05")
}

// protobuf helpers

func autoreplyEncodeVarint(v uint64) []byte {
	var buf [10]byte
	var n int
	for v >= 0x80 {
		buf[n] = byte(v&0x7F | 0x80)
		n++
		v >>= 7
	}
	buf[n] = byte(v)
	n++
	return buf[:n]
}

func autoreplyEncodeTag(fieldNumber, wireType int) []byte {
	return autoreplyEncodeVarint(uint64(fieldNumber<<3) | uint64(wireType))
}

func autoreplyEncodeString(fieldNumber int, s string) []byte {
	b := []byte(s)
	return bytes.Join([][]byte{
		autoreplyEncodeTag(fieldNumber, 2),
		autoreplyEncodeVarint(uint64(len(b))),
		b,
	}, nil)
}

func autoreplyEncodeInt32(fieldNumber int, v int32) []byte {
	return append(autoreplyEncodeTag(fieldNumber, 0), autoreplyEncodeVarint(uint64(v))...)
}

func autoreplyEncodeInt64(fieldNumber int, v int64) []byte {
	return append(autoreplyEncodeTag(fieldNumber, 0), autoreplyEncodeVarint(uint64(v))...)
}

func autoreplyEncodeDouble(fieldNumber int, v float64) []byte {
	buf := make([]byte, 8)
	binary.LittleEndian.PutUint64(buf, math.Float64bits(v))
	return append(autoreplyEncodeTag(fieldNumber, 1), buf...)
}

func autoreplyEncodeMessage(fieldNumber int, data []byte) []byte {
	return bytes.Join([][]byte{
		autoreplyEncodeTag(fieldNumber, 2),
		autoreplyEncodeVarint(uint64(len(data))),
		data,
	}, nil)
}

func autoreplyReadVarint(data []byte, pos int) (uint64, int, bool) {
	var value uint64
	var shift uint
	for pos < len(data) {
		b := data[pos]
		pos++
		value |= uint64(b&0x7F) << shift
		if b&0x80 == 0 {
			return value, pos, true
		}
		shift += 7
		if shift > 63 {
			return 0, pos, false
		}
	}
	return 0, pos, false
}

func autoreplyRandomHex(n int) string {
	b := make([]byte, n)
	rand.Read(b)
	return hex.EncodeToString(b)
}

func autoreplyRandomAlphanumeric(n int, upper bool) string {
	const chars = "0123456789abcdefghijklmnopqrstuvwxyz"
	out := chars
	if upper {
		out = strings.ToUpper(chars)
	}
	var sb strings.Builder
	for i := 0; i < n; i++ {
		idx, _ := rand.Int(rand.Reader, big.NewInt(int64(len(out))))
		sb.WriteByte(out[idx.Int64()])
	}
	return sb.String()
}

func autoreplyGenerateDeviceIDs() map[string]string {
	androidID := autoreplyRandomHex(8)
	u := uuid.New().String()
	cuid := "baidutiebaapp" + u
	cuidGalaxy2 := strings.ToUpper(autoreplyRandomHex(16)) + "|" + autoreplyRandomAlphanumeric(9, true)
	c3Aid := "A00-" + strings.ToUpper(autoreplyRandomHex(16)) + "-" + autoreplyRandomAlphanumeric(8, true)
	sampleID := autoreplyRandomAlphanumeric(16, true)
	return map[string]string{
		"cuid":         cuid,
		"cuid_galaxy2": cuidGalaxy2,
		"c3_aid":       c3Aid,
		"android_id":   androidID,
		"z_id":         "",
		"sample_id":    sampleID,
	}
}

func autoreplyBuildPostProto(bduss, stoken, tbs, fname string, fid, tid int64, content, showName, quoteID, replyUID, floorNum, subPostID string) []byte {
	now := time.Now()
	timestamp := now.UnixMilli()
	eventDay := fmt.Sprintf("%d%d%d", now.Year(), int(now.Month()), now.Day())
	installTime := timestamp - 86400*30

	dev := autoreplyGenerateDeviceIDs()

	common := bytes.NewBuffer(nil)
	common.Write(autoreplyEncodeInt32(1, 2))
	common.Write(autoreplyEncodeString(2, "12.35.1.0"))
	common.Write(autoreplyEncodeString(3, dev["cuid"]))
	common.Write(autoreplyEncodeString(5, "000000000000000"))
	common.Write(autoreplyEncodeString(6, "1020031h"))
	common.Write(autoreplyEncodeString(7, dev["cuid_galaxy2"]))
	common.Write(autoreplyEncodeInt64(8, timestamp))
	common.Write(autoreplyEncodeString(9, "SM-G988N"))
	common.Write(autoreplyEncodeString(10, bduss))
	common.Write(autoreplyEncodeString(11, tbs))
	common.Write(autoreplyEncodeInt32(12, 1))
	common.Write(autoreplyEncodeString(24, "1.0.3"))
	common.Write(autoreplyEncodeString(25, "9"))
	common.Write(autoreplyEncodeString(26, "samsung"))
	common.Write(autoreplyEncodeString(28, "3.0.0"))
	common.Write(autoreplyEncodeString(29, ""))
	common.Write(autoreplyEncodeString(30, stoken))
	common.Write(autoreplyEncodeString(31, dev["z_id"]))
	common.Write(autoreplyEncodeString(32, dev["cuid_galaxy2"]))
	common.Write(autoreplyEncodeString(33, ""))
	common.Write(autoreplyEncodeString(34, ""))
	common.Write(autoreplyEncodeString(35, dev["c3_aid"]))
	common.Write(autoreplyEncodeString(36, dev["sample_id"]))
	common.Write(autoreplyEncodeInt32(37, 720))
	common.Write(autoreplyEncodeInt32(38, 1280))
	common.Write(autoreplyEncodeDouble(39, 1.5))
	common.Write(autoreplyEncodeInt32(40, 0))
	common.Write(autoreplyEncodeInt32(41, 0))
	common.Write(autoreplyEncodeString(42, "2.34.0"))
	common.Write(autoreplyEncodeString(43, "3340042"))
	common.Write(autoreplyEncodeString(44, "1038000"))
	common.Write(autoreplyEncodeInt64(49, installTime))
	common.Write(autoreplyEncodeInt64(50, installTime))
	common.Write(autoreplyEncodeInt64(51, installTime))
	common.Write(autoreplyEncodeString(53, eventDay))
	common.Write(autoreplyEncodeString(54, dev["android_id"]))
	common.Write(autoreplyEncodeInt32(55, 1))
	common.Write(autoreplyEncodeString(56, ""))
	common.Write(autoreplyEncodeInt32(57, 1))
	common.Write(autoreplyEncodeString(60, "0"))
	common.Write(autoreplyEncodeString(61, ""))
	common.Write(autoreplyEncodeString(62, "tieba/12.35.1.0"))
	common.Write(autoreplyEncodeInt32(63, 1))
	common.Write(autoreplyEncodeString(70, "0.4"))

	data := bytes.NewBuffer(nil)
	data.Write(autoreplyEncodeMessage(1, common.Bytes()))
	data.Write(autoreplyEncodeString(6, "1"))
	data.Write(autoreplyEncodeString(7, "0"))
	data.Write(autoreplyEncodeString(8, "0"))
	data.Write(autoreplyEncodeString(9, "0"))
	data.Write(autoreplyEncodeString(10, "0"))
	data.Write(autoreplyEncodeString(16, "12"))
	data.Write(autoreplyEncodeString(18, "1"))
	data.Write(autoreplyEncodeString(19, content))
	data.Write(autoreplyEncodeString(26, strconv.FormatInt(fid, 10)))
	if quoteID == "" {
		data.Write(autoreplyEncodeString(28, ""))
		data.Write(autoreplyEncodeString(29, ""))
	}
	data.Write(autoreplyEncodeString(30, fname))
	data.Write(autoreplyEncodeString(31, "0"))
	if quoteID == "" {
		data.Write(autoreplyEncodeString(32, "0"))
	}
	data.Write(autoreplyEncodeString(45, strconv.FormatInt(tid, 10)))
	if quoteID != "" {
		data.Write(autoreplyEncodeString(46, quoteID))
	}
	data.Write(autoreplyEncodeString(47, "0"))
	data.Write(autoreplyEncodeString(48, floorNum))
	if quoteID != "" {
		data.Write(autoreplyEncodeString(49, quoteID))
	}
	if subPostID != "" {
		data.Write(autoreplyEncodeString(50, subPostID))
	}
	data.Write(autoreplyEncodeString(51, "0"))
	data.Write(autoreplyEncodeString(52, "0"))
	data.Write(autoreplyEncodeString(53, "0"))
	if subPostID == "" {
		if quoteID == "" {
			data.Write(autoreplyEncodeString(55, "13"))
		} else {
			data.Write(autoreplyEncodeString(55, "0"))
		}
	}
	data.Write(autoreplyEncodeString(58, showName))
	data.Write(autoreplyEncodeString(60, "0"))
	if quoteID != "" && replyUID != "" {
		data.Write(autoreplyEncodeString(20, replyUID))
	}
	data.Write(autoreplyEncodeInt32(64, 0))
	data.Write(autoreplyEncodeInt32(67, 0))

	return autoreplyEncodeMessage(1, data.Bytes())
}

func autoreplyParseError(data []byte) (int, string) {
	var errorno int
	var errmsg string
	pos := 0
	for pos < len(data) {
		tag, newPos, ok := autoreplyReadVarint(data, pos)
		if !ok {
			break
		}
		pos = newPos
		fieldNumber := int(tag >> 3)
		wireType := int(tag & 0x07)
		if wireType == 0 && fieldNumber == 1 {
			v, np, ok := autoreplyReadVarint(data, pos)
			if !ok {
				break
			}
			errorno = int(v)
			pos = np
		} else if wireType == 2 && fieldNumber == 2 {
			length, np, ok := autoreplyReadVarint(data, pos)
			if !ok {
				break
			}
			pos = np
			end := pos + int(length)
			if end > len(data) {
				break
			}
			errmsg = string(data[pos:end])
			pos = end
		} else {
			np := autoreplySkipField(data, pos, wireType)
			if np < 0 {
				break
			}
			pos = np
		}
	}
	return errorno, errmsg
}

func autoreplyParseNeedVcode(data []byte) bool {
	needVcode := false
	pos := 0
	for pos < len(data) {
		tag, newPos, ok := autoreplyReadVarint(data, pos)
		if !ok {
			break
		}
		pos = newPos
		fieldNumber := int(tag >> 3)
		wireType := int(tag & 0x07)
		if wireType == 0 {
			_, np, ok := autoreplyReadVarint(data, pos)
			if !ok {
				break
			}
			pos = np
		} else if wireType == 2 {
			length, np, ok := autoreplyReadVarint(data, pos)
			if !ok {
				break
			}
			pos = np
			end := pos + int(length)
			if end > len(data) {
				break
			}
			if fieldNumber == 14 {
				spos := 0
				for spos < end-pos {
					stag, snp, ok := autoreplyReadVarint(data[pos:end], spos)
					if !ok {
						break
					}
					spos = snp
					sfield := int(stag >> 3)
					swire := int(stag & 0x07)
					if swire == 2 && sfield == 3 {
						slen, snp2, ok := autoreplyReadVarint(data[pos:end], spos)
						if ok {
							spos = snp2
							vend := spos + int(slen)
							if vend <= end-pos {
								v := string(data[pos+spos : pos+vend])
								needVcode = v != "" && v != "0"
							}
						}
						break
					} else if swire == 0 {
						_, snp2, ok := autoreplyReadVarint(data[pos:end], spos)
						if !ok {
							break
						}
						spos = snp2
					} else if swire == 2 {
						slen, snp2, ok := autoreplyReadVarint(data[pos:end], spos)
						if !ok {
							break
						}
						spos = snp2 + int(slen)
					} else {
						break
					}
				}
			}
			pos = end
		} else {
			break
		}
	}
	return needVcode
}

func autoreplySkipField(data []byte, pos, wireType int) int {
	switch wireType {
	case 0:
		_, np, ok := autoreplyReadVarint(data, pos)
		if !ok {
			return -1
		}
		return np
	case 1:
		return pos + 8
	case 2:
		length, np, ok := autoreplyReadVarint(data, pos)
		if !ok {
			return -1
		}
		return np + int(length)
	case 5:
		return pos + 4
	}
	return -1
}

func autoreplyParseResponse(binary []byte) (errorno int, errmsg string, needVcode bool) {
	pos := 0
	for pos < len(binary) {
		tag, newPos, ok := autoreplyReadVarint(binary, pos)
		if !ok {
			break
		}
		pos = newPos
		fieldNumber := int(tag >> 3)
		wireType := int(tag & 0x07)
		switch wireType {
		case 0:
			_, np, ok := autoreplyReadVarint(binary, pos)
			if !ok {
				return
			}
			pos = np
		case 1:
			pos += 8
		case 2:
			length, np, ok := autoreplyReadVarint(binary, pos)
			if !ok {
				return
			}
			pos = np
			end := pos + int(length)
			if end > len(binary) {
				return
			}
			sub := binary[pos:end]
			if fieldNumber == 1 {
				errorno, errmsg = autoreplyParseError(sub)
			} else if fieldNumber == 2 {
				needVcode = autoreplyParseNeedVcode(sub)
			}
			pos = end
		case 5:
			pos += 4
		default:
			return
		}
	}
	return
}

type autoreplyAddPostResult struct {
	Success   bool   `json:"success"`
	ErrorCode int    `json:"error_code"`
	ErrorMsg  string `json:"error_msg"`
	NeedVcode bool   `json:"need_vcode"`
}

func autoreplyAddPost(bduss, stoken, tbs, fname string, fid, tid int64, content, showName, quoteID, replyUID, floorNum, subPostID string) autoreplyAddPostResult {
	protoBinary := autoreplyBuildPostProto(bduss, stoken, tbs, fname, fid, tid, content, showName, quoteID, replyUID, floorNum, subPostID)

	boundary := "-*_r1999"
	var body bytes.Buffer
	body.WriteString("--" + boundary + "\r\n")
	body.WriteString("Content-Disposition: form-data; name=\"data\"; filename=\"file\"\r\n\r\n")
	body.Write(protoBinary)
	body.WriteString("\r\n--" + boundary + "--\r\n")

	url := "https://tiebac.baidu.com/c/c/post/add?cmd=309731"
	req, err := http.NewRequest(http.MethodPost, url, &body)
	if err != nil {
		return autoreplyAddPostResult{Success: false, ErrorCode: -1, ErrorMsg: "Request Error: " + err.Error()}
	}
	req.Header.Set("Content-Type", "multipart/form-data; boundary="+boundary)
	req.Header.Set("User-Agent", "tieba/12.35.1.0")
	req.Header.Set("x_bd_data_type", "protobuf")
	req.Header.Set("Accept-Encoding", "gzip")
	req.Header.Set("Connection", "keep-alive")
	req.Header.Set("Cookie", "BDUSS="+bduss+"; STOKEN="+stoken+";")

	client := &http.Client{
		Timeout:   60 * time.Second,
		Transport: _function.TBClient.Transport,
	}
	resp, err := client.Do(req)
	if err != nil {
		return autoreplyAddPostResult{Success: false, ErrorCode: -1, ErrorMsg: "CURL Error: " + err.Error()}
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		var raw bytes.Buffer
		io.CopyN(&raw, resp.Body, 128)
		return autoreplyAddPostResult{Success: false, ErrorCode: resp.StatusCode, ErrorMsg: "HTTP Error: " + strconv.Itoa(resp.StatusCode)}
	}

	var reader io.Reader = resp.Body
	if strings.EqualFold(resp.Header.Get("Content-Encoding"), "gzip") {
		gr, err := gzip.NewReader(reader)
		if err == nil {
			defer gr.Close()
			reader = gr
		}
	}
	response, err := io.ReadAll(reader)
	if err != nil {
		return autoreplyAddPostResult{Success: false, ErrorCode: -1, ErrorMsg: "Read Error: " + err.Error()}
	}

	errorno, errmsg, needVcode := autoreplyParseResponse(response)
	return autoreplyAddPostResult{
		Success:   errorno == 0,
		ErrorCode: errorno,
		ErrorMsg:  errmsg,
		NeedVcode: needVcode,
	}
}

type weltolkFloor struct {
	ID       int64           `json:"id"`
	AuthorID int64           `json:"author_id"`
	Floor    int64           `json:"floor"`
	Username string          `json:"username"`
	Portrait string          `json:"portrait"`
	Content  string          `json:"content"`
	SubPosts []*weltolkFloor `json:"sub_posts"`
}

func weltolkToInt64(v any) int64 {
	switch x := v.(type) {
	case json.Number:
		if i, err := x.Int64(); err == nil {
			return i
		}
	case string:
		if i, err := strconv.ParseInt(x, 10, 64); err == nil {
			return i
		}
	case float64:
		return int64(x)
	case int64:
		return x
	case int32:
		return int64(x)
	case int:
		return int64(x)
	}
	return 0
}

func weltolkToString(v any) string {
	switch x := v.(type) {
	case string:
		return x
	case json.Number:
		return x.String()
	case float64:
		return strconv.FormatInt(int64(x), 10)
	case int64:
		return strconv.FormatInt(x, 10)
	case int32:
		return strconv.FormatInt(int64(x), 10)
	case int:
		return strconv.Itoa(x)
	}
	return ""
}

func weltolkExtractTextContent(content any) string {
	arr, ok := content.([]any)
	if !ok {
		return ""
	}
	var sb strings.Builder
	for _, item := range arr {
		m, ok := item.(map[string]any)
		if !ok {
			continue
		}
		typeVal := m["type"]
		if typeVal == nil {
			continue
		}
		match := false
		switch t := typeVal.(type) {
		case json.Number:
			if t.String() == "0" {
				match = true
			}
		case float64:
			if int64(t) == 0 {
				match = true
			}
		case int, int32, int64:
			if weltolkToInt64(t) == 0 {
				match = true
			}
		case string:
			if t == "0" {
				match = true
			}
		}
		if match {
			sb.WriteString(weltolkToString(m["text"]))
		}
	}
	return sb.String()
}

func weltolkParseAuthorName(author any, authorID int64) string {
	m, ok := author.(map[string]any)
	if !ok {
		if authorID != 0 {
			return fmt.Sprintf("用户%d", authorID)
		}
		return ""
	}
	if v := weltolkToString(m["name_show"]); v != "" {
		return v
	}
	if v := weltolkToString(m["name"]); v != "" {
		return v
	}
	if authorID != 0 {
		return fmt.Sprintf("用户%d", authorID)
	}
	return ""
}

func weltolkCallTiebaJSONAPI(tid int64, bduss string, pn, rn, r string) (map[string]any, error) {
	secret := "tiebaclient!!!"
	stTime := mrand.Intn(751) + 100
	stSize := int64(math.Round((float64(mrand.Int63())/float64(math.MaxInt64)*8 + 0.4) * float64(stTime)))
	cuid := fmt.Sprintf("baidutiebaapp%08d", mrand.Intn(90000000)+10000000)

	params := map[string]string{
		"_client_type":    "2",
		"_client_version": "12.41.7.1",
		"_phone_imei":     "000000000000000",
		"back":            "0",
		"cuid":            cuid,
		"floor_rn":        "3",
		"from":            "tieba",
		"kz":              strconv.FormatInt(tid, 10),
		"lz":              "0",
		"mark":            "0",
		"model":           "2201123C",
		"pn":              pn,
		"r":               r,
		"rn":              rn,
		"stErrorNums":     "1",
		"stMethod":        "1",
		"stMode":          "1",
		"stTimesNum":      "1",
		"stTime":          strconv.Itoa(stTime),
		"stSize":          strconv.FormatInt(stSize, 10),
		"st_type":         "tb_frslist",
		"with_floor":      "1",
	}

	keys := make([]string, 0, len(params))
	for k := range params {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var raw strings.Builder
	for _, k := range keys {
		raw.WriteString(k + "=" + params[k])
	}
	params["sign"] = _function.Md5(raw.String() + secret)

	body := url.Values{}
	for k, v := range params {
		body.Set(k, v)
	}

	headers := map[string]string{
		"User-Agent": "bdtb for Android 12.41.7.1",
		"Cookie":     "ka=open; BDUSS=" + url.QueryEscape(bduss),
	}

	client := &http.Client{
		Timeout:   15 * time.Second,
		Transport: _function.TBClient.Transport,
	}
	resp, err := _function.Fetch("http://c.tieba.baidu.com/c/f/pb/page", http.MethodPost, []byte(body.Encode()), headers, client)
	if err != nil {
		return nil, err
	}
	if len(resp) == 0 {
		return nil, errors.New("empty response")
	}

	var result map[string]any
	dec := json.NewDecoder(bytes.NewReader(resp))
	dec.UseNumber()
	if err := dec.Decode(&result); err != nil {
		return nil, err
	}
	return result, nil
}

func weltolkGetReplyCount(tid int64, bduss string) (int64, bool) {
	resp, err := weltolkCallTiebaJSONAPI(tid, bduss, "1", "1", "0")
	if err != nil || resp == nil {
		return 0, false
	}
	thread, ok := resp["thread"].(map[string]any)
	if !ok {
		return 0, false
	}
	v, ok := thread["reply_num"]
	if !ok {
		return 0, false
	}
	return weltolkToInt64(v), true
}

func weltolkGetLastFloorContent(tid int64, bduss string, limit int) []*weltolkFloor {
	resp, err := weltolkCallTiebaJSONAPI(tid, bduss, "1", strconv.Itoa(limit), "0")
	if err != nil || resp == nil {
		return nil
	}
	postListRaw, ok := resp["post_list"].([]any)
	if !ok || len(postListRaw) == 0 {
		return nil
	}

	var result []*weltolkFloor
	for _, postRaw := range postListRaw {
		post, ok := postRaw.(map[string]any)
		if !ok {
			continue
		}
		authorID := weltolkToInt64(post["author_id"])
		username := ""
		if authorID != 0 {
			username = fmt.Sprintf("用户%d", authorID)
		}
		if author, ok := post["author"].(map[string]any); ok {
			if n := weltolkParseAuthorName(author, authorID); n != "" {
				username = n
			}
		}

		floor := &weltolkFloor{
			ID:       weltolkToInt64(post["id"]),
			AuthorID: authorID,
			Floor:    weltolkToInt64(post["floor"]),
			Username: username,
			Portrait: "",
			Content:  weltolkExtractTextContent(post["content"]),
		}

		if splRaw, ok := post["sub_post_list"].(map[string]any); ok {
			if subListRaw, ok := splRaw["sub_post_list"].([]any); ok {
				for _, spRaw := range subListRaw {
					sp, ok := spRaw.(map[string]any)
					if !ok {
						continue
					}
					spAuthorID := weltolkToInt64(sp["author_id"])
					spUsername := ""
					spPortrait := ""
					if spAuthor, ok := sp["author"].(map[string]any); ok {
						spPortrait = weltolkToString(spAuthor["portrait"])
						spUsername = weltolkParseAuthorName(spAuthor, spAuthorID)
					}
					if spUsername == "" && spAuthorID != 0 {
						spUsername = fmt.Sprintf("用户%d", spAuthorID)
					}
					floor.SubPosts = append(floor.SubPosts, &weltolkFloor{
						ID:       weltolkToInt64(sp["id"]),
						AuthorID: spAuthorID,
						Username: spUsername,
						Portrait: spPortrait,
						Content:  weltolkExtractTextContent(sp["content"]),
					})
				}
			}
		}
		result = append(result, floor)
	}
	return result
}

func (pluginInfo *WeltolkAutoReplyPluginType) Action() {
	if !pluginInfo.PluginInfo.CheckActive() {
		return
	}
	defer pluginInfo.PluginInfo.SetActive(false)

	now := time.Now().Unix()
	logTime := weltolkAutoreplyNowString(now)

	highWater, _ := strconv.Atoi(_function.GetOption(weltolkAutoreplyHighWaterKey))
	if highWater < 0 {
		highWater = 0
	}

	var tasks []*model.TcWeltolkAutoreplyTasks
	err := _function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("enabled = ? AND id >= ?", 1, highWater).Order("id ASC").Find(&tasks).Error
	if err != nil {
		slog.Error("plugin.weltolk-autoreply.action.query", "error", err)
		return
	}
	if len(tasks) == 0 {
		_function.SetOption(weltolkAutoreplyHighWaterKey, 0)
		err = _function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("enabled = ?", 1).Order("id ASC").Find(&tasks).Error
		if err != nil {
			slog.Error("plugin.weltolk-autoreply.action.query2", "error", err)
			return
		}
	}

	for _, task := range tasks {
		atUsername := ""
		atPortrait := ""
		quoteID := ""
		replyUID := ""
		floorNum := ""
		subPostID := ""
		taskID := task.ID

		slog.Debug("plugin.weltolk-autoreply.action.task", "id", taskID, "fname", task.Fname, "tid", task.Tid)

		if strings.TrimSpace(task.ReplyContent) == "" {
			slog.Debug("plugin.weltolk-autoreply.action.skip-empty", "id", taskID)
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             task.Pid,
				"last_status":     "skipped",
				"last_error":      "",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：回复内容为空<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}

		var bind model.TcBaiduid
		if err := _function.GormDB.R.Model(&model.TcBaiduid{}).Where("uid = ?", task.UID).Order("id ASC").Take(&bind).Error; err != nil {
			slog.Debug("plugin.weltolk-autoreply.action.no-bind", "id", taskID, "uid", task.UID, "error", err)
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             task.Pid,
				"last_status":     "error",
				"last_error":      "未找到贴吧绑定信息",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：未找到贴吧绑定信息<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}
		pid := bind.ID

		cookie := _function.GetCookie(pid, true)
		if cookie == nil || cookie.Bduss == "" {
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             pid,
				"last_status":     "error",
				"last_error":      "未获取到BDUSS",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：未获取到BDUSS<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}
		bduss := cookie.Bduss
		stoken := cookie.Stoken

		replyCount, ok := weltolkGetReplyCount(task.Tid, bduss)
		if !ok || replyCount < 0 {
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             pid,
				"last_status":     "error",
				"last_error":      "获取回复数失败",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：获取回复数失败<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}

		triggerMode := task.TriggerMode
		if triggerMode == "" {
			triggerMode = "new_floor"
		}
		replyTarget := task.ReplyTarget
		if replyTarget == "" {
			replyTarget = "floor"
		}
		allowReplied := task.AllowReplied == 1
		keywordMaxSeenPid := int64(0)

		if triggerMode != "keyword" {
			latestFloors := weltolkGetLastFloorContent(task.Tid, bduss, 1)
			latestPid := int64(0)
			if len(latestFloors) > 0 {
				latestPid = latestFloors[0].ID
			}
			if !allowReplied && latestPid <= task.LastRepliedPid {
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_status":     "skipped",
					"last_error":      "",
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：没有新楼层<br>", logTime))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}
			if len(latestFloors) == 0 {
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_status":     "error",
					"last_error":      "获取楼层内容失败",
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：获取楼层内容失败<br>", logTime))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}
			latest := latestFloors[0]
			quoteID = strconv.FormatInt(latest.ID, 10)
			replyUID = strconv.FormatInt(latest.AuthorID, 10)
			floorNum = strconv.FormatInt(latest.Floor, 10)
			atUsername = latest.Username
			atPortrait = latest.Portrait
			subPostID = ""
		}

		if triggerMode == "keyword" {
			atUsername = ""
			atPortrait = ""
			quoteID = ""
			replyUID = ""
			floorNum = ""
			subPostID = ""

			floors := weltolkGetLastFloorContent(task.Tid, bduss, 20)
			if len(floors) == 0 {
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_status":     "skipped",
					"last_error":      "获取楼层内容失败",
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：获取楼层内容失败<br>", logTime))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}

			for _, floor := range floors {
				if floor.ID > keywordMaxSeenPid {
					keywordMaxSeenPid = floor.ID
				}
			}

			var newFloors []*weltolkFloor
			for _, floor := range floors {
				if allowReplied || floor.ID > task.LastRepliedPid {
					newFloors = append(newFloors, floor)
				}
			}
			if len(newFloors) == 0 {
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_status":     "skipped",
					"last_error":      "",
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：无新楼层<br>", logTime))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}

			matched := false
			keywords := strings.Split(task.MatchKeywords, "\n")
			for _, floor := range newFloors {
				if strings.TrimSpace(floor.Content) == "" {
					continue
				}
				for _, kw := range keywords {
					kw = strings.TrimSpace(kw)
					if kw == "" {
						continue
					}
					if strings.Contains(strings.ToLower(floor.Content), strings.ToLower(kw)) {
						matched = true
						quoteID = strconv.FormatInt(floor.ID, 10)
						floorNum = strconv.FormatInt(floor.Floor, 10)
						atUsername = floor.Username
						atPortrait = floor.Portrait
						replyUID = strconv.FormatInt(floor.AuthorID, 10)

						if replyTarget == "subpost" && len(floor.SubPosts) > 0 {
							subMatched := false
							for _, sp := range floor.SubPosts {
								if strings.TrimSpace(sp.Content) == "" {
									continue
								}
								if strings.Contains(strings.ToLower(sp.Content), strings.ToLower(kw)) {
									subPostID = strconv.FormatInt(sp.ID, 10)
									replyUID = strconv.FormatInt(sp.AuthorID, 10)
									atUsername = sp.Username
									atPortrait = sp.Portrait
									subMatched = true
									break
								}
							}
							if !subMatched {
								subPostID = ""
								atUsername = floor.Username
								atPortrait = floor.Portrait
								replyUID = strconv.FormatInt(floor.AuthorID, 10)
							}
						} else {
							subPostID = ""
							atUsername = floor.Username
							atPortrait = floor.Portrait
							replyUID = strconv.FormatInt(floor.AuthorID, 10)
						}
						goto keywordMatched
					}
				}
			}
		keywordMatched:
			if !matched {
				newPid := task.LastRepliedPid
				if !allowReplied {
					newPid = keywordMaxSeenPid
				}
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":              pid,
					"last_replied_pid": newPid,
					"last_status":      "skipped",
					"last_error":       "",
					"last_check_time":  now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：关键词未匹配（水位推进至%d）<br>", logTime, keywordMaxSeenPid))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}
		}

		if task.LastReplyTime > 0 && now-int64(task.LastReplyTime) < int64(task.ReplyInterval) {
			remaining := task.ReplyInterval - int32(now-int64(task.LastReplyTime))
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             pid,
				"last_status":     "skipped",
				"last_error":      "",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：回复间隔未到（需等待 %d 秒）<br>", logTime, remaining))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}

		if task.ReplyProbability < 100 {
			r := mrand.Intn(100) + 1
			if r > int(task.ReplyProbability) {
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_status":     "skipped",
					"last_error":      "",
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：概率未命中<br>", logTime))
				_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
				continue
			}
		}

		tbsResp, err := _function.GetTbs(bduss)
		if err != nil || tbsResp == nil || tbsResp.Tbs == "" {
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             pid,
				"last_status":     "error",
				"last_error":      "获取TBS失败",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：获取TBS失败<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}
		tbs := tbsResp.Tbs

		fid := _function.GetFid(task.Fname)
		if fid == 0 {
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":             pid,
				"last_status":     "error",
				"last_error":      "获取fid失败",
				"last_check_time": now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：获取fid失败<br>", logTime))
			_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
			continue
		}

		floorForReplace := strconv.FormatInt(replyCount, 10)
		if triggerMode == "keyword" && floorNum != "" {
			floorForReplace = floorNum
		}
		finalContent := strings.NewReplacer(
			"{floor}", floorForReplace,
			"{time}", time.Unix(now, 0).Format("2006-01-02 15:04:05"),
			"{date}", time.Unix(now, 0).Format("2006-01-02"),
			"{tid}", strconv.FormatInt(task.Tid, 10),
			"{username}", atUsername,
		).Replace(task.ReplyContent)

		if triggerMode == "keyword" && replyTarget == "subpost" && subPostID != "" && atUsername != "" {
			finalContent = fmt.Sprintf("回复 #(reply, %s, %s) :%s", atPortrait, atUsername, finalContent)
		}

		showName := "贴吧用户"
		result := autoreplyAddPost(bduss, stoken, tbs, task.Fname, fid, task.Tid, finalContent, showName, quoteID, replyUID, floorNum, subPostID)

		if result.Success {
			newLastRepliedPid := task.LastRepliedPid
			if !allowReplied {
				if triggerMode == "keyword" {
					newLastRepliedPid = keywordMaxSeenPid
				} else {
					newLastRepliedPid = weltolkToInt64(quoteID)
				}
			}
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":              pid,
				"last_floor":       replyCount,
				"last_replied_pid": newLastRepliedPid,
				"last_reply_time":  now,
				"retry_count":      0,
				"last_status":      "ok",
				"last_error":       "",
				"last_check_time":  now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：操作成功<br>", logTime))
		} else if result.NeedVcode {
			vcodePid := task.LastRepliedPid
			if !allowReplied {
				if triggerMode == "keyword" {
					vcodePid = keywordMaxSeenPid
				} else {
					vcodePid = weltolkToInt64(quoteID)
				}
			}
			_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
				"pid":              pid,
				"last_floor":       replyCount,
				"last_replied_pid": vcodePid,
				"last_status":      "vcode",
				"last_error":       "触发验证码",
				"last_check_time":  now,
			})
			weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：跳过：触发验证码<br>", logTime))
		} else {
			newRetry := task.RetryCount + 1
			if newRetry >= 3 {
				lastError := fmt.Sprintf("重试次数达上限: [%d] %s", result.ErrorCode, result.ErrorMsg)
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_floor":      replyCount,
					"retry_count":     newRetry,
					"enabled":         0,
					"last_status":     "error",
					"last_error":      lastError,
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：失败：重试次数达上限，任务已禁用#[%d] %s<br>", logTime, result.ErrorCode, result.ErrorMsg))
			} else {
				lastError := fmt.Sprintf("[错误码 %d] %s", result.ErrorCode, result.ErrorMsg)
				_function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ?", taskID).Updates(map[string]any{
					"pid":             pid,
					"last_floor":      replyCount,
					"retry_count":     newRetry,
					"last_status":     "error",
					"last_error":      lastError,
					"last_check_time": now,
				})
				weltolkAutoreplyAppendLog(taskID, fmt.Sprintf("[%s] 执行结果：操作失败#[%d] %s<br>", logTime, result.ErrorCode, result.ErrorMsg))
			}
		}

		_function.SetOption(weltolkAutoreplyHighWaterKey, int(taskID)+1)
	}

	_function.SetOption(weltolkAutoreplyHighWaterKey, 0)
}

func (pluginInfo *WeltolkAutoReplyPluginType) Install() error {
	var err error
	for k, v := range pluginInfo.Options {
		_function.SetOption(k, v)
	}
	err = UpdatePluginInfo(pluginInfo.Name, pluginInfo.Version, false, "")
	if err != nil {
		return err
	}
	return _function.GormDB.W.Migrator().CreateTable(&model.TcWeltolkAutoreplyTasks{})
}

func (pluginInfo *WeltolkAutoReplyPluginType) Delete() error {
	for k := range pluginInfo.Options {
		_function.DeleteOption(k)
	}
	DeletePluginInfo(pluginInfo.Name)
	_function.GormDB.W.Migrator().DropTable(&model.TcWeltolkAutoreplyTasks{})
	_function.GormDB.W.Where("name = ?", weltolkAutoreplyLimitKey).Delete(&model.TcUsersOption{})
	_function.GormDB.W.Where("name = ?", weltolkAutoreplyOpenKey).Delete(&model.TcUsersOption{})
	return nil
}

func (pluginInfo *WeltolkAutoReplyPluginType) Upgrade() error {
	return nil
}

func (pluginInfo *WeltolkAutoReplyPluginType) RemoveAccount(_type string, id int32, tx *gorm.DB) error {
	_sql := _function.GormDB.W
	if tx != nil {
		_sql = tx
	}
	return _sql.Where(_type+" = ?", id).Delete(&model.TcWeltolkAutoreplyTasks{}).Error
}

func (pluginInfo *WeltolkAutoReplyPluginType) Report(int32, *gorm.DB) (string, error) {
	return "", nil
}

func (pluginInfo *WeltolkAutoReplyPluginType) Reset(uid, pid, tid int32) error {
	if uid == 0 {
		return errors.New("invalid uid")
	}
	_sql := _function.GormDB.W.Model(&model.TcWeltolkAutoreplyTasks{}).Where("uid = ?", uid)
	if pid != 0 {
		_sql = _sql.Where("pid = ?", pid)
	}
	if tid != 0 {
		_sql = _sql.Where("id = ?", tid)
	}
	return _sql.Updates(map[string]any{
		"enabled":     1,
		"retry_count": 0,
		"last_status": "",
		"last_error":  "",
	}).Error
}

func (pluginInfo *WeltolkAutoReplyPluginType) ExportAccount(uid int32, tx *gorm.DB) (map[string]any, error) {
	if !pluginInfo.GetSwitch() {
		return nil, nil
	}
	tableName := (&model.TcWeltolkAutoreplyTasks{}).TableName()
	var exportData []*model.TcWeltolkAutoreplyTasks
	if tx == nil {
		tx = _function.GormDB.R
	}
	err := tx.Model(&model.TcWeltolkAutoreplyTasks{}).Where("uid = ?", uid).Find(&exportData).Error
	return map[string]any{
		tableName: exportData,
		"tc_users_options": _function.GetUserOptionBatch(strconv.Itoa(int(uid)), _function.OptionExt{
			Tx:      tx,
			KeyName: weltolkAutoreplyLimitKey,
		}),
	}, err
}

func (pluginInfo *WeltolkAutoReplyPluginType) ImportAccount(uid int32, pid map[int32]int32, data map[string]json.RawMessage, tx *gorm.DB) error {
	if !pluginInfo.GetSwitch() {
		return errors.New("plugin is not enabled")
	}
	if tx == nil {
		tx = _function.GormDB.W
	}
	tableName := (&model.TcWeltolkAutoreplyTasks{}).TableName()
	var data2 []*model.TcWeltolkAutoreplyTasks
	if err := _function.JsonDecode(data[tableName], &data2); err != nil {
		return errors.New("invalid data format")
	}
	var data3 []*model.TcWeltolkAutoreplyTasks
	for i := range data2 {
		if newPid, ok := pid[data2[i].Pid]; ok {
			data2[i].ID = 0
			data2[i].UID = uid
			data2[i].Pid = newPid
			data3 = append(data3, data2[i])
		}
	}
	if len(data3) == 0 {
		return nil
	}
	return tx.Model(&model.TcWeltolkAutoreplyTasks{}).Create(data3).Error
}

// endpoints

func PluginWeltolkAutoReplyGetSwitch(c echo.Context) error {
	uid := c.Get("uid").(string)
	status := _function.GetUserOption(weltolkAutoreplyOpenKey, uid)
	if status == "" {
		status = "0"
		_function.SetUserOption(weltolkAutoreplyOpenKey, status, uid)
	}
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", status != "0", "tbsign"))
}

func PluginWeltolkAutoReplySwitch(c echo.Context) error {
	uid := c.Get("uid").(string)
	status := _function.GetUserOption(weltolkAutoreplyOpenKey, uid) != "0"
	err := _function.SetUserOption(weltolkAutoreplyOpenKey, !status, uid)
	if err != nil {
		slog.Debug("plugin.weltolk-autoreply.switch", "uid", uid, "current_status", status, "error", err)
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "无法修改自动回帖插件状态", status, "tbsign"))
	}
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", !status, "tbsign"))
}

func PluginWeltolkAutoReplyList(c echo.Context) error {
	uid := c.Get("uid").(string)
	var tasks []*model.TcWeltolkAutoreplyTasks
	_function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("uid = ?", uid).Order("id DESC").Find(&tasks)
	limit := weltolkAutoreplyGetUserLimit(uid)
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", map[string]any{
		"tasks": tasks,
		"count": len(tasks),
		"limit": limit,
	}, "tbsign"))
}

type weltolkAutoReplyListAddBinding struct {
	Pid              int32  `json:"pid" form:"pid"`
	Fname            string `json:"fname" form:"fname"`
	Tid              int64  `json:"tid" form:"tid"`
	ReplyContent     string `json:"reply_content" form:"reply_content"`
	ReplyInterval    int32  `json:"reply_interval" form:"reply_interval"`
	ReplyProbability int32  `json:"reply_probability" form:"reply_probability"`
	TriggerMode      string `json:"trigger_mode" form:"trigger_mode"`
	ReplyTarget      string `json:"reply_target" form:"reply_target"`
	AllowReplied     int32  `json:"allow_replied" form:"allow_replied"`
	MatchKeywords    string `json:"match_keywords" form:"match_keywords"`
	Enabled          int32  `json:"enabled" form:"enabled"`
}

func PluginWeltolkAutoReplyListAdd(c echo.Context) error {
	uid := c.Get("uid").(string)
	binding := new(weltolkAutoReplyListAddBinding)
	if err := c.Bind(binding); err != nil {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "error", _function.EchoEmptyObject, "tbsign"))
	}
	numUID, _ := strconv.Atoi(uid)

	var count int64
	if err := _function.GormDB.R.Model(&model.TcBaiduid{}).Where("id = ? AND uid = ?", binding.Pid, numUID).Count(&count).Error; err != nil || count == 0 {
		return c.JSON(http.StatusForbidden, _function.ApiTemplate(403, "越权操作：该百度账号不属于您", _function.EchoEmptyObject, "tbsign"))
	}

	if strings.TrimSpace(binding.Fname) == "" || binding.Tid == 0 || strings.TrimSpace(binding.ReplyContent) == "" {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "请填写所有必填字段", _function.EchoEmptyObject, "tbsign"))
	}

	limit := weltolkAutoreplyGetUserLimit(uid)
	var userCount int64
	_function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("uid = ?", numUID).Count(&userCount)
	if int(userCount) >= limit {
		return c.JSON(http.StatusForbidden, _function.ApiTemplate(403, fmt.Sprintf("已达到最大任务数限制（%d 条）", limit), _function.EchoEmptyObject, "tbsign"))
	}

	if binding.ReplyInterval <= 0 {
		binding.ReplyInterval = 300
	}
	if binding.ReplyProbability <= 0 || binding.ReplyProbability > 100 {
		binding.ReplyProbability = 100
	}
	if binding.TriggerMode == "" {
		binding.TriggerMode = "new_floor"
	}
	if binding.ReplyTarget == "" {
		binding.ReplyTarget = "floor"
	}
	if binding.Enabled != 0 && binding.Enabled != 1 {
		binding.Enabled = 1
	}

	task := &model.TcWeltolkAutoreplyTasks{
		UID:              int32(numUID),
		Pid:              binding.Pid,
		Fname:            strings.TrimSpace(binding.Fname),
		Tid:              binding.Tid,
		ReplyContent:     binding.ReplyContent,
		ReplyInterval:    binding.ReplyInterval,
		ReplyProbability: binding.ReplyProbability,
		Enabled:          binding.Enabled,
		TriggerMode:      binding.TriggerMode,
		ReplyTarget:      binding.ReplyTarget,
		AllowReplied:     binding.AllowReplied,
		MatchKeywords:    binding.MatchKeywords,
	}
	if err := _function.GormDB.W.Create(task).Error; err != nil {
		slog.Error("plugin.weltolk-autoreply.list.add", "uid", uid, "error", err)
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "未知错误", _function.EchoEmptyObject, "tbsign"))
	}
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", task, "tbsign"))
}

func PluginWeltolkAutoReplyListDelete(c echo.Context) error {
	uid := c.Get("uid").(string)
	idStr := c.Param("id")
	id, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "error", _function.EchoEmptyObject, "tbsign"))
	}

	var task model.TcWeltolkAutoreplyTasks
	if err := _function.GormDB.R.Model(&model.TcWeltolkAutoreplyTasks{}).Where("id = ? AND uid = ?", id, uid).Take(&task).Error; err != nil {
		return c.JSON(http.StatusNotFound, _function.ApiTemplate(404, "任务不存在", _function.EchoEmptyObject, "tbsign"))
	}

	if err := _function.GormDB.W.Where("id = ? AND uid = ?", id, uid).Delete(&model.TcWeltolkAutoreplyTasks{}).Error; err != nil {
		slog.Error("plugin.weltolk-autoreply.list.delete", "uid", uid, "id", id, "error", err)
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "未知错误", _function.EchoEmptyObject, "tbsign"))
	}
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", task, "tbsign"))
}

func PluginWeltolkAutoReplyListEmpty(c echo.Context) error {
	uid := c.Get("uid").(string)
	if err := _function.GormDB.W.Where("uid = ?", uid).Delete(&model.TcWeltolkAutoreplyTasks{}).Error; err != nil {
		slog.Error("plugin.weltolk-autoreply.list.empty", "uid", uid, "error", err)
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "未知错误", _function.EchoEmptyObject, "tbsign"))
	}
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", _function.EchoEmptyObject, "tbsign"))
}

type weltolkAutoReplyTestBinding struct {
	Pid           int32  `json:"pid" form:"pid"`
	Fname         string `json:"fname" form:"fname"`
	Tid           int64  `json:"tid" form:"tid"`
	ReplyContent  string `json:"reply_content" form:"reply_content"`
	TriggerMode   string `json:"trigger_mode" form:"trigger_mode"`
	ReplyTarget   string `json:"reply_target" form:"reply_target"`
	AllowReplied  int32  `json:"allow_replied" form:"allow_replied"`
	MatchKeywords string `json:"match_keywords" form:"match_keywords"`
}

func PluginWeltolkAutoReplyTest(c echo.Context) error {
	uid := c.Get("uid").(string)
	binding := new(weltolkAutoReplyTestBinding)
	if err := c.Bind(binding); err != nil {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "error", _function.EchoEmptyObject, "tbsign"))
	}
	numUID, _ := strconv.Atoi(uid)

	var count int64
	if err := _function.GormDB.R.Model(&model.TcBaiduid{}).Where("id = ? AND uid = ?", binding.Pid, numUID).Count(&count).Error; err != nil || count == 0 {
		return c.JSON(http.StatusForbidden, _function.ApiTemplate(403, "越权操作：该百度账号不属于您", _function.EchoEmptyObject, "tbsign"))
	}
	if strings.TrimSpace(binding.Fname) == "" || binding.Tid == 0 || strings.TrimSpace(binding.ReplyContent) == "" {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "测试发帖失败：请填写贴吧名称、帖子ID、回帖内容，并选择发帖账号", _function.EchoEmptyObject, "tbsign"))
	}

	cookie := _function.GetCookie(binding.Pid, true)
	if cookie == nil || cookie.Bduss == "" {
		return c.JSON(http.StatusForbidden, _function.ApiTemplate(403, "测试发帖失败：无法获取所选账号的 BDUSS", _function.EchoEmptyObject, "tbsign"))
	}
	bduss := cookie.Bduss
	stoken := cookie.Stoken

	tbsResp, err := _function.GetTbs(bduss)
	if err != nil || tbsResp == nil || tbsResp.Tbs == "" {
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "测试发帖失败：获取 TBS 失败", _function.EchoEmptyObject, "tbsign"))
	}
	tbs := tbsResp.Tbs

	fid := _function.GetFid(binding.Fname)
	if fid == 0 {
		return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "测试发帖失败：获取 fid 失败", _function.EchoEmptyObject, "tbsign"))
	}

	triggerMode := binding.TriggerMode
	if triggerMode == "" {
		triggerMode = "new_floor"
	}
	replyTarget := binding.ReplyTarget
	if replyTarget == "" {
		replyTarget = "floor"
	}

	quoteID := ""
	replyUID := ""
	floorNum := ""
	subPostID := ""
	atUsername := ""
	atPortrait := ""

	if triggerMode == "keyword" {
		if strings.TrimSpace(binding.MatchKeywords) == "" {
			return c.JSON(http.StatusOK, _function.ApiTemplate(200, "关键词模式但未设置关键词，将作为主题回复", _function.EchoEmptyObject, "tbsign"))
		}
		floors := weltolkGetLastFloorContent(binding.Tid, bduss, 20)
		if len(floors) == 0 {
			return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "获取楼层内容失败，无法测试关键词匹配", _function.EchoEmptyObject, "tbsign"))
		}
		matched := false
		keywords := strings.Split(binding.MatchKeywords, "\n")
		for _, floor := range floors {
			if strings.TrimSpace(floor.Content) == "" {
				continue
			}
			for _, kw := range keywords {
				kw = strings.TrimSpace(kw)
				if kw == "" {
					continue
				}
				if strings.Contains(strings.ToLower(floor.Content), strings.ToLower(kw)) {
					matched = true
					quoteID = strconv.FormatInt(floor.ID, 10)
					floorNum = strconv.FormatInt(floor.Floor, 10)
					atUsername = floor.Username
					atPortrait = floor.Portrait
					replyUID = strconv.FormatInt(floor.AuthorID, 10)
					if replyTarget == "subpost" && len(floor.SubPosts) > 0 {
						subMatched := false
						for _, sp := range floor.SubPosts {
							if strings.Contains(strings.ToLower(sp.Content), strings.ToLower(kw)) {
								subPostID = strconv.FormatInt(sp.ID, 10)
								replyUID = strconv.FormatInt(sp.AuthorID, 10)
								atUsername = sp.Username
								atPortrait = sp.Portrait
								subMatched = true
								break
							}
						}
						_ = subMatched
					}
					goto testMatched
				}
			}
		}
	testMatched:
		if !matched {
			return c.JSON(http.StatusOK, _function.ApiTemplate(200, "关键词未匹配任何楼层", _function.EchoEmptyObject, "tbsign"))
		}
	} else {
		latestFloors := weltolkGetLastFloorContent(binding.Tid, bduss, 1)
		if len(latestFloors) > 0 {
			latest := latestFloors[0]
			quoteID = strconv.FormatInt(latest.ID, 10)
			replyUID = strconv.FormatInt(latest.AuthorID, 10)
			floorNum = strconv.FormatInt(latest.Floor, 10)
			atUsername = latest.Username
			atPortrait = latest.Portrait
		}
	}

	floorForReplace := floorNum
	if floorForReplace == "" {
		floorForReplace = "测试"
	}
	now := time.Now().Unix()
	content := strings.NewReplacer(
		"{floor}", floorForReplace,
		"{time}", time.Unix(now, 0).Format("2006-01-02 15:04:05"),
		"{date}", time.Unix(now, 0).Format("2006-01-02"),
		"{tid}", strconv.FormatInt(binding.Tid, 10),
		"{username}", atUsername,
	).Replace(binding.ReplyContent)

	if triggerMode == "keyword" && replyTarget == "subpost" && subPostID != "" && atUsername != "" {
		content = fmt.Sprintf("回复 #(reply, %s, %s) :%s", atPortrait, atUsername, content)
	}

	result := autoreplyAddPost(bduss, stoken, tbs, binding.Fname, fid, binding.Tid, content, "贴吧用户", quoteID, replyUID, floorNum, subPostID)
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", result, "tbsign"))
}

func PluginWeltolkAutoReplySettings(c echo.Context) error {
	uid := c.Get("uid").(string)
	global := _function.GetOption(weltolkAutoreplyLimitKey)
	if global == "" {
		global = "5"
	}
	personal := _function.GetUserOption(weltolkAutoreplyLimitKey, uid)
	return c.JSON(http.StatusOK, _function.ApiTemplate(200, "OK", map[string]string{
		"global_limit":    global,
		"personal_limit":  personal,
	}, "tbsign"))
}

type weltolkAutoReplySettingsUpdateBinding struct {
	GlobalLimit    *int `json:"global_limit" form:"global_limit"`
	PersonalLimit  *int `json:"personal_limit" form:"personal_limit"`
}

func PluginWeltolkAutoReplySettingsUpdate(c echo.Context) error {
	uid := c.Get("uid").(string)
	role, _ := c.Get("role").(string)
	isAdmin := role == _function.RoleAdmin

	binding := new(weltolkAutoReplySettingsUpdateBinding)
	if err := c.Bind(binding); err != nil {
		return c.JSON(http.StatusBadRequest, _function.ApiTemplate(400, "error", _function.EchoEmptyObject, "tbsign"))
	}

	if binding.GlobalLimit != nil {
		if !isAdmin {
			return c.JSON(http.StatusForbidden, _function.ApiTemplate(403, "无权修改全局限额", _function.EchoEmptyObject, "tbsign"))
		}
		if *binding.GlobalLimit < 1 {
			*binding.GlobalLimit = 1
		}
		if err := _function.SetOption(weltolkAutoreplyLimitKey, *binding.GlobalLimit); err != nil {
			return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "保存失败", _function.EchoEmptyObject, "tbsign"))
		}
	}

	if binding.PersonalLimit != nil {
		if *binding.PersonalLimit < 0 {
			*binding.PersonalLimit = 0
		}
		if err := _function.SetUserOption(weltolkAutoreplyLimitKey, *binding.PersonalLimit, uid); err != nil {
			return c.JSON(http.StatusInternalServerError, _function.ApiTemplate(500, "保存失败", _function.EchoEmptyObject, "tbsign"))
		}
	}

	return PluginWeltolkAutoReplySettings(c)
}
